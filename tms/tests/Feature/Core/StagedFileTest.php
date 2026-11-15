<?php

use App\Modules\Core\Tenancy\StagedFile;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;

beforeEach(fn () => fakeImportDisk());

it('cleans only the temporary files older than ten minutes, never an upload in flight', function () {
    inTenant();
    $disk = Storage::disk(TenantStorage::DISK);
    $directory = TenantStorage::path('companies', 'x');
    $disk->put($directory.'/.tmp-old.png', 'old');
    $disk->put($directory.'/.tmp-recent.png', 'recent');
    touch($disk->path($directory.'/.tmp-old.png'), time() - 3600);

    $staged = StagedFile::stage($directory, UploadedFile::fake()->createWithContent('a.png', 'new'), 'png');

    expect($disk->exists($directory.'/.tmp-old.png'))->toBeFalse()
        ->and($disk->exists($directory.'/.tmp-recent.png'))->toBeTrue()
        ->and($staged)->not->toBeNull();
});

it('moves the staged file over the final one without deleting it first', function () {
    inTenant();
    $disk = Storage::disk(TenantStorage::DISK);
    $directory = TenantStorage::path('companies', 'y');
    $disk->put($directory.'/logo.png', 'before');

    $staged = StagedFile::stage($directory, UploadedFile::fake()->createWithContent('a.png', 'after'), 'png');
    StagedFile::promote((string) $staged, $directory.'/logo.png', null, fn () => $GLOBALS['promoted'] = $disk->get($directory.'/logo.png'));

    expect($disk->get($directory.'/logo.png'))->toBe('after')
        ->and($disk->exists((string) $staged))->toBeFalse()
        ->and($GLOBALS['promoted'] ?? null)->toBe('after');
});

it('skips a temporary file that vanishes during the cleanup', function () {
    inTenant();
    $directory = TenantStorage::path('companies', 'z');
    $real = Storage::disk(TenantStorage::DISK);
    $real->put($directory.'/.tmp-gone.png', 'x');

    $disk = Mockery::mock($real)->makePartial();
    $disk->shouldReceive('lastModified')->andThrow(new UnableToRetrieveMetadata('gone'));
    Storage::set(TenantStorage::DISK, $disk);

    expect(StagedFile::stage($directory, UploadedFile::fake()->createWithContent('a.png', 'new'), 'png'))->not->toBeNull();
});

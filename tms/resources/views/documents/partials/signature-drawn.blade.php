{{-- The signer's drawn signature, put in place of PdfSource::SLOT when a document signed online is rendered again. --}}
<div class="signature-drawn" style="margin-top: 6pt;">
    <img src="{{ $drawing }}" alt="Signature" style="display: block; max-height: 60pt; max-width: 200pt;">
    <div style="font-size: 8pt; color: #52606d;">Signé électroniquement le {{ $signed_on }} par {{ $signer }}</div>
</div>

import { Button } from '@/components/ui/button';
import { Eraser } from 'lucide-react';
import { PointerEvent, useEffect, useRef } from 'react';

const WIDTH = 600;
const HEIGHT = 200;
/** Points below which a drawing counts as empty (a tap is not a signature). */
const MIN_POINTS = 10;

interface Props {
    id?: string;
    /** PNG data URI of the drawing, '' when empty or cleared. */
    onChange: (dataUri: string) => void;
    invalid?: boolean;
}

/** A box to sign by hand (finger, mouse or stylus), exported as a transparent PNG. */
export default function SignaturePad({ id, onChange, invalid }: Props) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const points = useRef(0);

    useEffect(() => {
        const context = canvas.current?.getContext('2d');
        if (context) {
            context.lineWidth = 3;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#14203c';
        }
    }, []);

    const position = (e: PointerEvent<HTMLCanvasElement>) => {
        const box = e.currentTarget.getBoundingClientRect();

        return { x: ((e.clientX - box.left) * WIDTH) / box.width, y: ((e.clientY - box.top) * HEIGHT) / box.height };
    };

    const start = (e: PointerEvent<HTMLCanvasElement>) => {
        const context = e.currentTarget.getContext('2d');
        if (!context) return;
        e.currentTarget.setPointerCapture(e.pointerId);
        drawing.current = true;
        const { x, y } = position(e);
        context.beginPath();
        context.moveTo(x, y);
    };

    const move = (e: PointerEvent<HTMLCanvasElement>) => {
        const context = e.currentTarget.getContext('2d');
        if (!drawing.current || !context) return;
        const { x, y } = position(e);
        context.lineTo(x, y);
        context.stroke();
        points.current += 1;
    };

    const end = (e: PointerEvent<HTMLCanvasElement>) => {
        if (!drawing.current) return;
        drawing.current = false;
        onChange(points.current >= MIN_POINTS ? e.currentTarget.toDataURL('image/png') : '');
    };

    const clear = () => {
        canvas.current?.getContext('2d')?.clearRect(0, 0, WIDTH, HEIGHT);
        points.current = 0;
        onChange('');
    };

    return (
        <div className="grid gap-2">
            <canvas
                id={id}
                ref={canvas}
                width={WIDTH}
                height={HEIGHT}
                aria-label="Cadre de signature : dessinez votre signature"
                className={`aspect-[3/1] w-full cursor-crosshair touch-none rounded-md border bg-white ${invalid ? 'border-destructive' : 'border-input'}`}
                onPointerDown={start}
                onPointerMove={move}
                onPointerUp={end}
                onPointerCancel={end}
            />
            <Button type="button" variant="ghost" size="sm" className="justify-self-end" onClick={clear}>
                <Eraser className="h-4 w-4" />
                Effacer
            </Button>
        </div>
    );
}

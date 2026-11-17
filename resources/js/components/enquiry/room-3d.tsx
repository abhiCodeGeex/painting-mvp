import { ContactShadows, Environment, Lightformer, OrbitControls, useTexture } from '@react-three/drei';
import { Canvas, useThree, type ThreeEvent } from '@react-three/fiber';
import { Camera, Download, Eye, Lamp, Maximize2, Minimize2, Move, RotateCcw, Sofa, X, ZoomIn, ZoomOut } from 'lucide-react';
import { Suspense, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import * as THREE from 'three';
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib';
import { type Unit } from '@/lib/units';
import { cn } from '@/lib/utils';
import type { PreviewFrame, PreviewMat } from './wall-preview';

/**
 * Interactive 3D room preview (Three.js via React Three Fiber). Everything is in metres and
 * true to scale: the artwork hangs on the customer's wall colour above a 2 m sofa.
 *
 * Controls: drag = look around · scroll / pinch = zoom · right-drag / two fingers = move ·
 * drag the artwork itself = reposition it on the wall.
 */
export interface Room3DProps {
    imageUrl: string | null;
    widthMm: number;
    heightMm: number;
    frame: PreviewFrame | null;
    mat: PreviewMat | null;
    depthMm?: number;
    edgeStyle?: string | null;
    glazing?: 'none' | 'standard' | 'low';
    wallColour: string;
    wallWidthMm?: number | null;
    wallHeightMm?: number | null;
    unit?: Unit;
    className?: string;
}

const M = (mm: number) => mm / 1000;
const SOFA = { w: 2.0, h: 0.85, d: 0.9 };
const ROOM_DEPTH = 4.2;
const EYE = 1.55;

type ViewCommand = { kind: 'reset' | 'closeup' | 'zoomIn' | 'zoomOut'; n: number };

export default function Room3D(props: Room3DProps) {
    const { widthMm, heightMm, frame, mat, wallColour, className } = props;
    const containerRef = useRef<HTMLDivElement>(null);
    const glRef = useRef<THREE.WebGLRenderer | null>(null);

    const [showSofa, setShowSofa] = useState(true);
    const [pictureLight, setPictureLight] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    const [command, setCommand] = useState<ViewCommand>({ kind: 'reset', n: 0 });
    const [offset, setOffset] = useState<{ x: number; y: number } | null>(null);
    const [dragging, setDragging] = useState(false);
    const [showHint, setShowHint] = useState(true);

    // Real sizes (metres).
    const picW = M(Math.max(50, widthMm || 0));
    const picH = M(Math.max(50, heightMm || 0));
    const matB = mat ? M(mat.border_mm) : 0;
    const matBottom = mat ? M(mat.bottom_weight_mm ?? 0) : 0;
    const frameW = frame ? M(frame.width_mm) : 0;
    const outerW = picW + 2 * matB + 2 * frameW;
    const outerH = picH + 2 * matB + matBottom + 2 * frameW;

    const wallW = Math.max(M(props.wallWidthMm || 0) || 3.6, outerW + 1.2, SOFA.w + 1.2);
    const wallH = Math.max(M(props.wallHeightMm || 0) || 2.7, outerH + SOFA.h + 1.0);

    // Default hanging: centre at ~1.5 m, at least 20 cm above the sofa back.
    const defaultY = Math.max(1.5, SOFA.h + 0.2 + outerH / 2);
    const art = {
        x: offset?.x ?? 0,
        y: offset?.y ?? defaultY,
    };

    // Reset position when the size changes a lot (e.g. another artwork chosen).
    useEffect(() => setOffset(null), [widthMm, heightMm]);

    // Fullscreen handling.
    useEffect(() => {
        const onChange = () => setFullscreen(document.fullscreenElement === containerRef.current);
        document.addEventListener('fullscreenchange', onChange);
        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);
    const toggleFullscreen = () => {
        if (!containerRef.current) return;
        if (document.fullscreenElement) document.exitFullscreen();
        else containerRef.current.requestFullscreen?.();
    };

    const run = (kind: ViewCommand['kind']) => setCommand((c) => ({ kind, n: c.n + 1 }));

    const saveImage = () => {
        const canvas = glRef.current?.domElement;
        if (!canvas) return;
        const link = document.createElement('a');
        link.download = 'artwork-preview.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    };

    return (
        <div ref={containerRef} className={cn('relative overflow-hidden rounded-lg border bg-neutral-900', fullscreen ? 'h-screen' : 'h-[360px]', className)}>
            <Canvas
                shadows
                dpr={[1, 2]}
                frameloop="demand"
                gl={{ preserveDrawingBuffer: true, antialias: true }}
                camera={{ fov: 45, near: 0.05, far: 50, position: [0, EYE, 3] }}
                onCreated={({ gl }) => {
                    glRef.current = gl;
                    gl.toneMapping = THREE.ACESFilmicToneMapping;
                }}
                style={{ cursor: dragging ? 'grabbing' : 'auto', touchAction: 'none' }}
            >
                <SceneLights pictureLight={pictureLight} art={art} outerW={outerW} outerH={outerH} />
                <Room wallW={wallW} wallH={wallH} wallColour={wallColour} />
                {showSofa && <SofaModel />}

                <DragPlane
                    active={dragging}
                    wallW={wallW}
                    wallH={wallH}
                    outerW={outerW}
                    outerH={outerH}
                    onMove={(x, y) => setOffset({ x, y })}
                    onEnd={() => setDragging(false)}
                />

                <group
                    position={[art.x, art.y, 0]}
                    onPointerDown={(e: ThreeEvent<PointerEvent>) => {
                        e.stopPropagation();
                        setDragging(true);
                    }}
                    onPointerOver={() => !dragging && (document.body.style.cursor = 'grab')}
                    onPointerOut={() => (document.body.style.cursor = 'auto')}
                >
                    <FramedArtwork3D {...props} picW={picW} picH={picH} matB={matB} matBottom={matBottom} frameW={frameW} outerW={outerW} outerH={outerH} />
                </group>

                <ContactShadows position={[0, 0.001, 1.2]} scale={[wallW, 3]} opacity={0.35} blur={2.4} far={1.2} resolution={512} />

                <CameraRig
                    command={command}
                    target={[art.x, art.y, 0]}
                    outerW={outerW}
                    outerH={outerH}
                    wallW={wallW}
                    wallH={wallH}
                    enabled={!dragging}
                />
            </Canvas>

            {/* Toolbar */}
            <div className="absolute top-2 right-2 flex flex-col gap-1">
                <ToolButton label="Reset view" onClick={() => run('reset')}>
                    <RotateCcw className="size-4" />
                </ToolButton>
                <ToolButton label="Look at the artwork" onClick={() => run('closeup')}>
                    <Eye className="size-4" />
                </ToolButton>
                <ToolButton label="Zoom in" onClick={() => run('zoomIn')}>
                    <ZoomIn className="size-4" />
                </ToolButton>
                <ToolButton label="Zoom out" onClick={() => run('zoomOut')}>
                    <ZoomOut className="size-4" />
                </ToolButton>
                <ToolButton label={pictureLight ? 'Picture light off' : 'Picture light on'} active={pictureLight} onClick={() => setPictureLight((v) => !v)}>
                    <Lamp className="size-4" />
                </ToolButton>
                <ToolButton label={showSofa ? 'Hide sofa' : 'Show sofa'} active={showSofa} onClick={() => setShowSofa((v) => !v)}>
                    <Sofa className="size-4" />
                </ToolButton>
                <ToolButton label="Centre the artwork" onClick={() => setOffset(null)}>
                    <Move className="size-4" />
                </ToolButton>
                <ToolButton label="Save image" onClick={saveImage}>
                    <Download className="size-4" />
                </ToolButton>
                <ToolButton label={fullscreen ? 'Exit full screen' : 'Full screen'} onClick={toggleFullscreen}>
                    {fullscreen ? <Minimize2 className="size-4" /> : <Maximize2 className="size-4" />}
                </ToolButton>
            </div>

            {showHint && (
                <button
                    type="button"
                    onClick={() => setShowHint(false)}
                    title="Hide tips"
                    className="absolute bottom-2 left-2 flex max-w-[calc(100%-3.5rem)] items-center gap-1.5 rounded bg-black/55 px-2 py-1 text-left text-[11px] text-white"
                >
                    <Camera className="size-3.5 shrink-0" />
                    <span>Drag: look around · Scroll/pinch: zoom · Right-drag: move · Drag the artwork to move it</span>
                    <X className="size-3 shrink-0 opacity-70" />
                </button>
            )}
        </div>
    );
}

function ToolButton({ label, onClick, active = false, children }: { label: string; onClick: () => void; active?: boolean; children: ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            onClick={onClick}
            className={cn(
                'flex size-8 items-center justify-center rounded-md border border-white/15 text-white shadow backdrop-blur transition',
                active ? 'bg-amber-500/80 hover:bg-amber-500' : 'bg-black/50 hover:bg-black/70',
            )}
        >
            {children}
        </button>
    );
}

/* ------------------------------------------------------------------ */
/* Camera                                                               */
/* ------------------------------------------------------------------ */

function CameraRig({
    command,
    target,
    outerW,
    outerH,
    wallW,
    wallH,
    enabled,
}: {
    command: ViewCommand;
    target: [number, number, number];
    outerW: number;
    outerH: number;
    wallW: number;
    wallH: number;
    enabled: boolean;
}) {
    const controls = useRef<OrbitControlsImpl>(null);
    const { camera, invalidate } = useThree();

    // Stand near the back of the room so wall, floor and sofa are all in view.
    const roomDistance = Math.min(ROOM_DEPTH - 0.15, Math.max(3.4, wallW * 1.05));
    const closeDistance = Math.max(0.6, Math.max(outerW, outerH) * 1.6);

    const place = useCallback(
        (distance: number, lookAt: THREE.Vector3, height: number) => {
            camera.position.set(lookAt.x, height, distance);
            controls.current?.target.copy(lookAt);
            controls.current?.update();
            invalidate();
        },
        [camera, invalidate],
    );

    useEffect(() => {
        const c = controls.current;
        if (!c) return;
        const artPoint = new THREE.Vector3(...target);
        switch (command.kind) {
            case 'reset':
                // Eye height, looking slightly down towards the artwork and sofa.
                place(roomDistance, new THREE.Vector3(0, Math.min(1.2, wallH / 2), 0), EYE);
                break;
            case 'closeup':
                place(closeDistance, artPoint, target[1]);
                break;
            case 'zoomIn':
            case 'zoomOut': {
                const dir = new THREE.Vector3().subVectors(camera.position, c.target);
                const factor = command.kind === 'zoomIn' ? 0.75 : 1.33;
                const len = THREE.MathUtils.clamp(dir.length() * factor, c.minDistance, c.maxDistance);
                camera.position.copy(c.target).add(dir.setLength(len));
                c.update();
                invalidate();
                break;
            }
        }
        // Only react to new commands.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [command.n]);

    return (
        <OrbitControls
            ref={controls}
            makeDefault
            enabled={enabled}
            enableDamping
            dampingFactor={0.12}
            screenSpacePanning
            minDistance={0.35}
            maxDistance={ROOM_DEPTH - 0.1}
            // Stay in front of the wall and above the floor.
            minAzimuthAngle={-Math.PI * 0.42}
            maxAzimuthAngle={Math.PI * 0.42}
            minPolarAngle={Math.PI * 0.18}
            maxPolarAngle={Math.PI * 0.62}
            onChange={() => {
                const c = controls.current;
                if (!c) return;
                // Keep panning inside the room.
                c.target.x = THREE.MathUtils.clamp(c.target.x, -wallW / 2, wallW / 2);
                c.target.y = THREE.MathUtils.clamp(c.target.y, 0.2, wallH - 0.1);
                c.target.z = THREE.MathUtils.clamp(c.target.z, 0, 1.5);
                camera.position.z = Math.max(camera.position.z, 0.15);
                camera.position.y = THREE.MathUtils.clamp(camera.position.y, 0.15, wallH - 0.05);
            }}
        />
    );
}

/* ------------------------------------------------------------------ */
/* Lights & room                                                        */
/* ------------------------------------------------------------------ */

function SceneLights({ pictureLight, art, outerW, outerH }: { pictureLight: boolean; art: { x: number; y: number }; outerW: number; outerH: number }) {
    const spot = useRef<THREE.SpotLight>(null);
    const targetObj = useMemo(() => new THREE.Object3D(), []);
    targetObj.position.set(art.x, art.y, 0);

    useEffect(() => {
        if (spot.current) spot.current.target = targetObj;
    }, [targetObj]);

    return (
        <>
            <ambientLight intensity={pictureLight ? 0.35 : 0.55} />
            <hemisphereLight args={['#fff7ed', '#7c6a58', pictureLight ? 0.35 : 0.6]} />
            <directionalLight
                position={[1.8, 3.2, 3.2]}
                intensity={pictureLight ? 0.6 : 1.4}
                castShadow
                shadow-mapSize={[2048, 2048]}
                shadow-bias={-0.0004}
                shadow-normalBias={0.02}
                shadow-camera-left={-4}
                shadow-camera-right={4}
                shadow-camera-top={4}
                shadow-camera-bottom={-1}
            />
            {/* Local reflections for glass and metal, no network needed. */}
            <Environment resolution={128}>
                <Lightformer form="rect" intensity={2} position={[0, 2.5, 4]} scale={[6, 2, 1]} color="#fff8ef" />
                <Lightformer form="rect" intensity={1} position={[-4, 1.5, 2]} rotation-y={Math.PI / 2} scale={[4, 2, 1]} />
                <Lightformer form="rect" intensity={0.6} position={[4, 1.5, 2]} rotation-y={-Math.PI / 2} scale={[4, 2, 1]} color="#e6eefc" />
            </Environment>
            {pictureLight && (
                <>
                    <spotLight
                        ref={spot}
                        position={[art.x, art.y + outerH / 2 + 0.25, 0.45]}
                        angle={0.75}
                        penumbra={0.6}
                        intensity={6}
                        distance={3}
                        decay={1.5}
                        color="#ffe2b8"
                    />
                    <primitive object={targetObj} />
                    {/* the lamp: a horizontal brass bar on a short arm above the frame */}
                    <group position={[art.x, art.y + outerH / 2 + 0.07, 0]}>
                        <mesh position={[0, 0, 0.12]} rotation={[0, 0, Math.PI / 2]} castShadow>
                            <cylinderGeometry args={[0.022, 0.022, Math.min(0.6, Math.max(0.25, outerW * 0.55)), 24]} />
                            <meshStandardMaterial color="#b08d57" metalness={0.85} roughness={0.3} />
                        </mesh>
                        <mesh position={[0, 0.03, 0.06]} rotation={[Math.PI / 2, 0, 0]} castShadow>
                            <cylinderGeometry args={[0.008, 0.008, 0.12, 12]} />
                            <meshStandardMaterial color="#b08d57" metalness={0.85} roughness={0.3} />
                        </mesh>
                    </group>
                </>
            )}
        </>
    );
}

function Room({ wallW, wallH, wallColour }: { wallW: number; wallH: number; wallColour: string }) {
    const floorTexture = useMemo(() => woodTexture(), []);
    useEffect(() => {
        floorTexture.repeat.set(wallW / 1.2, ROOM_DEPTH / 1.2);
    }, [floorTexture, wallW]);

    return (
        <group>
            {/* back wall */}
            <mesh position={[0, wallH / 2, 0]} receiveShadow>
                <planeGeometry args={[wallW, wallH]} />
                <meshStandardMaterial color={wallColour} roughness={0.95} />
            </mesh>
            {/* side walls */}
            {[-1, 1].map((side) => (
                <mesh key={side} position={[(side * wallW) / 2, wallH / 2, ROOM_DEPTH / 2]} rotation={[0, (-side * Math.PI) / 2, 0]} receiveShadow>
                    <planeGeometry args={[ROOM_DEPTH, wallH]} />
                    <meshStandardMaterial color={wallColour} roughness={0.95} />
                </mesh>
            ))}
            {/* floor */}
            <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, 0, ROOM_DEPTH / 2]} receiveShadow>
                <planeGeometry args={[wallW, ROOM_DEPTH]} />
                <meshStandardMaterial map={floorTexture} roughness={0.7} />
            </mesh>
            {/* ceiling */}
            <mesh rotation={[Math.PI / 2, 0, 0]} position={[0, wallH, ROOM_DEPTH / 2]}>
                <planeGeometry args={[wallW, ROOM_DEPTH]} />
                <meshStandardMaterial color="#f7f5f1" roughness={1} />
            </mesh>
            {/* skirting board */}
            <mesh position={[0, 0.045, 0.008]} receiveShadow>
                <boxGeometry args={[wallW, 0.09, 0.016]} />
                <meshStandardMaterial color="#f4f1ec" roughness={0.6} />
            </mesh>
        </group>
    );
}

function SofaModel() {
    const fabric = <meshStandardMaterial color="#5b6472" roughness={1} />;
    const legs = <meshStandardMaterial color="#3a2a1f" roughness={0.5} />;
    return (
        <group position={[0, 0, 0.12 + SOFA.d / 2]}>
            {/* seat */}
            <mesh position={[0, 0.3, 0.05]} castShadow receiveShadow>
                <boxGeometry args={[SOFA.w - 0.3, 0.2, SOFA.d - 0.25]} />
                {fabric}
            </mesh>
            {/* base */}
            <mesh position={[0, 0.16, 0]} castShadow receiveShadow>
                <boxGeometry args={[SOFA.w, 0.18, SOFA.d]} />
                {fabric}
            </mesh>
            {/* back */}
            <mesh position={[0, 0.58, -SOFA.d / 2 + 0.12]} castShadow receiveShadow>
                <boxGeometry args={[SOFA.w, 0.55, 0.24]} />
                {fabric}
            </mesh>
            {/* arms */}
            {[-1, 1].map((s) => (
                <mesh key={s} position={[(s * (SOFA.w - 0.15)) / 2, 0.4, 0]} castShadow receiveShadow>
                    <boxGeometry args={[0.15, 0.32, SOFA.d]} />
                    {fabric}
                </mesh>
            ))}
            {/* legs */}
            {[-1, 1].flatMap((sx) =>
                [-1, 1].map((sz) => (
                    <mesh key={`${sx}${sz}`} position={[sx * (SOFA.w / 2 - 0.08), 0.035, sz * (SOFA.d / 2 - 0.08)]} castShadow>
                        <boxGeometry args={[0.05, 0.07, 0.05]} />
                        {legs}
                    </mesh>
                )),
            )}
            {/* cushions */}
            {[-0.45, 0.45].map((x) => (
                <mesh key={x} position={[x, 0.62, -SOFA.d / 2 + 0.3]} rotation={[-0.25, 0, 0]} castShadow>
                    <boxGeometry args={[0.42, 0.38, 0.12]} />
                    <meshStandardMaterial color={x < 0 ? '#c9a27e' : '#e8dccb'} roughness={1} />
                </mesh>
            ))}
        </group>
    );
}

/** Invisible plane on the wall that turns pointer moves into a new artwork position. */
function DragPlane({
    active,
    wallW,
    wallH,
    outerW,
    outerH,
    onMove,
    onEnd,
}: {
    active: boolean;
    wallW: number;
    wallH: number;
    outerW: number;
    outerH: number;
    onMove: (x: number, y: number) => void;
    onEnd: () => void;
}) {
    useEffect(() => {
        if (!active) return;
        const up = () => {
            onEnd();
            document.body.style.cursor = 'auto';
        };
        window.addEventListener('pointerup', up);
        return () => window.removeEventListener('pointerup', up);
    }, [active, onEnd]);

    return (
        <mesh
            position={[0, wallH / 2, 0.05]}
            visible={false}
            onPointerMove={(e) => {
                if (!active) return;
                e.stopPropagation();
                const x = THREE.MathUtils.clamp(e.point.x, -wallW / 2 + outerW / 2 + 0.02, wallW / 2 - outerW / 2 - 0.02);
                const y = THREE.MathUtils.clamp(e.point.y, outerH / 2 + 0.1, wallH - outerH / 2 - 0.05);
                onMove(x, y);
            }}
        >
            <planeGeometry args={[wallW * 3, wallH * 3]} />
            <meshBasicMaterial />
        </mesh>
    );
}

/* ------------------------------------------------------------------ */
/* The framed artwork                                                   */
/* ------------------------------------------------------------------ */

function FramedArtwork3D(
    props: Room3DProps & { picW: number; picH: number; matB: number; matBottom: number; frameW: number; outerW: number; outerH: number },
) {
    const { frame, mat, picW, picH, matB, matBottom, frameW, outerW, outerH, glazing = 'none', depthMm = 0, edgeStyle } = props;
    const frameDepth = frame ? M(Math.max(15, frame.depth_mm ?? 25)) : 0;
    const canvasDepth = frame ? 0 : M(depthMm);

    // Front of the picture sits a little behind the front of the frame.
    const faceZ = frame ? frameDepth - 0.012 : canvasDepth;
    // Picture centre is shifted up when the mat has a weighted bottom.
    const picY = matBottom / 2;

    return (
        <group>
            <Suspense fallback={<PictureSurface texture={null} picW={picW} picH={picH} z={faceZ} y={picY} canvasDepth={canvasDepth} edgeStyle={edgeStyle} />}>
                <TexturedPicture url={props.imageUrl} picW={picW} picH={picH} z={faceZ} y={picY} canvasDepth={canvasDepth} edgeStyle={edgeStyle} />
            </Suspense>

            {mat && <MatBoard picW={picW} picH={picH} border={matB} bottom={matBottom} colour={mat.colour} core={mat.core_colour} z={faceZ + 0.002} />}

            {frame && <FrameBars outerW={outerW} outerH={outerH} frameW={frameW} depth={frameDepth} colour={frame.colour} finish={frame.finish} />}

            {glazing !== 'none' && frame && (
                <mesh position={[0, 0, faceZ + 0.004]}>
                    <planeGeometry args={[outerW - 2 * frameW, outerH - 2 * frameW]} />
                    <meshPhysicalMaterial
                        transparent
                        opacity={glazing === 'low' ? 0.04 : 0.12}
                        roughness={0.02}
                        metalness={0}
                        reflectivity={glazing === 'low' ? 0.1 : 0.9}
                        envMapIntensity={glazing === 'low' ? 0.2 : 1.6}
                        color="#ffffff"
                    />
                </mesh>
            )}

            {/* back panel so nothing looks hollow from the side */}
            {frame && (
                <mesh position={[0, 0, 0.002]}>
                    <planeGeometry args={[outerW - 0.004, outerH - 0.004]} />
                    <meshStandardMaterial color="#3d3a36" />
                </mesh>
            )}
        </group>
    );
}

function TexturedPicture({ url, ...rest }: { url: string | null } & Omit<PictureProps, 'texture'>) {
    if (!url) {
        return <PictureSurface texture={null} {...rest} />;
    }
    return <LoadedPicture url={url} {...rest} />;
}

function LoadedPicture({ url, ...rest }: { url: string } & Omit<PictureProps, 'texture'>) {
    const source = useTexture(url);
    const texture = useMemo(() => {
        const t = source.clone();
        t.colorSpace = THREE.SRGBColorSpace;
        t.anisotropy = 4;
        // "Cover" the picture area like CSS background-size: cover.
        const img = source.image as { width: number; height: number } | undefined;
        if (img?.width && img.height) {
            const imageAspect = img.width / img.height;
            const areaAspect = rest.picW / rest.picH;
            if (imageAspect > areaAspect) {
                t.repeat.set(areaAspect / imageAspect, 1);
                t.offset.set((1 - t.repeat.x) / 2, 0);
            } else {
                t.repeat.set(1, imageAspect / areaAspect);
                t.offset.set(0, (1 - t.repeat.y) / 2);
            }
        }
        t.needsUpdate = true;
        return t;
    }, [source, rest.picW, rest.picH]);

    return <PictureSurface texture={texture} {...rest} />;
}

interface PictureProps {
    texture: THREE.Texture | null;
    picW: number;
    picH: number;
    z: number;
    y: number;
    canvasDepth: number;
    edgeStyle?: string | null;
}

function PictureSurface({ texture, picW, picH, z, y, canvasDepth, edgeStyle }: PictureProps) {
    const placeholder = useMemo(() => gradientTexture(), []);
    const map = texture ?? placeholder;

    if (canvasDepth > 0) {
        // Stretched canvas without a frame: a box with the image on the front.
        const side =
            edgeStyle === 'solid_colour' ? (
                <meshStandardMaterial color="#f1efe9" roughness={0.9} />
            ) : (
                <meshStandardMaterial map={map} roughness={0.85} color="#d9d9d9" />
            );
        return (
            <mesh position={[0, y, canvasDepth / 2]} castShadow receiveShadow>
                <boxGeometry args={[picW, picH, canvasDepth]} />
                {side}
                {side}
                {side}
                {side}
                <meshStandardMaterial attach="material-4" map={map} roughness={0.85} />
                <meshStandardMaterial attach="material-5" color="#cfc8bd" />
            </mesh>
        );
    }

    return (
        <mesh position={[0, y, z]} castShadow receiveShadow>
            <planeGeometry args={[picW, picH]} />
            <meshStandardMaterial map={map} roughness={0.8} />
        </mesh>
    );
}

function MatBoard({ picW, picH, border, bottom, colour, core, z }: { picW: number; picH: number; border: number; bottom: number; colour: string; core: string; z: number }) {
    const overlap = 0.003;
    const bevel = 0.0025;
    const { matShape, bevelShape } = useMemo(() => {
        const w = picW + 2 * border;
        const h = picH + 2 * border + bottom;
        const cx = 0;
        const cy = bottom / 2;

        const outer = rectShape(0, 0, w, h);
        outer.holes.push(rectPath(cx, cy, picW - 2 * overlap + 2 * bevel, picH - 2 * overlap + 2 * bevel));

        const ring = rectShape(cx, cy, picW - 2 * overlap + 2 * bevel, picH - 2 * overlap + 2 * bevel);
        ring.holes.push(rectPath(cx, cy, picW - 2 * overlap, picH - 2 * overlap));

        return { matShape: outer, bevelShape: ring };
    }, [picW, picH, border, bottom]);

    return (
        <group position={[0, 0, z]}>
            {/* Thin board: no shadow casting (it caused striping on itself). */}
            <mesh receiveShadow>
                <extrudeGeometry args={[matShape, { depth: 0.0015, bevelEnabled: false }]} />
                <meshStandardMaterial color={colour} roughness={0.95} />
            </mesh>
            <mesh position={[0, 0, -0.0004]}>
                <extrudeGeometry args={[bevelShape, { depth: 0.0015, bevelEnabled: false }]} />
                <meshStandardMaterial color={core} roughness={0.9} />
            </mesh>
        </group>
    );
}

function FrameBars({ outerW, outerH, frameW, depth, colour, finish }: { outerW: number; outerH: number; frameW: number; depth: number; colour: string; finish: string }) {
    const material = useMemo(() => {
        const params: Record<string, { roughness: number; metalness: number }> = {
            matte: { roughness: 0.85, metalness: 0 },
            satin: { roughness: 0.5, metalness: 0.05 },
            gloss: { roughness: 0.18, metalness: 0.05 },
            metallic: { roughness: 0.32, metalness: 0.85 },
        };
        const p = params[finish] ?? params.matte;
        return new THREE.MeshStandardMaterial({ color: colour, roughness: p.roughness, metalness: p.metalness });
    }, [colour, finish]);

    const innerH = outerH - 2 * frameW;
    const z = depth / 2;

    return (
        <group>
            <mesh position={[0, outerH / 2 - frameW / 2, z]} material={material} castShadow receiveShadow>
                <boxGeometry args={[outerW, frameW, depth]} />
            </mesh>
            <mesh position={[0, -outerH / 2 + frameW / 2, z]} material={material} castShadow receiveShadow>
                <boxGeometry args={[outerW, frameW, depth]} />
            </mesh>
            <mesh position={[-outerW / 2 + frameW / 2, 0, z]} material={material} castShadow receiveShadow>
                <boxGeometry args={[frameW, innerH, depth]} />
            </mesh>
            <mesh position={[outerW / 2 - frameW / 2, 0, z]} material={material} castShadow receiveShadow>
                <boxGeometry args={[frameW, innerH, depth]} />
            </mesh>
            {/* inner lip giving a sense of the rebate */}
            <mesh position={[0, 0, depth - 0.006]} material={material}>
                <extrudeGeometry
                    args={[
                        (() => {
                            const s = rectShape(0, 0, outerW - 2 * frameW + 0.008, innerH + 0.008);
                            s.holes.push(rectPath(0, 0, outerW - 2 * frameW, innerH));
                            return s;
                        })(),
                        { depth: 0.004, bevelEnabled: false },
                    ]}
                />
            </mesh>
        </group>
    );
}

/* ------------------------------------------------------------------ */
/* Helpers                                                              */
/* ------------------------------------------------------------------ */

function rectShape(cx: number, cy: number, w: number, h: number): THREE.Shape {
    const s = new THREE.Shape();
    s.moveTo(cx - w / 2, cy - h / 2);
    s.lineTo(cx + w / 2, cy - h / 2);
    s.lineTo(cx + w / 2, cy + h / 2);
    s.lineTo(cx - w / 2, cy + h / 2);
    s.closePath();
    return s;
}

function rectPath(cx: number, cy: number, w: number, h: number): THREE.Path {
    const p = new THREE.Path();
    p.moveTo(cx - w / 2, cy - h / 2);
    p.lineTo(cx - w / 2, cy + h / 2);
    p.lineTo(cx + w / 2, cy + h / 2);
    p.lineTo(cx + w / 2, cy - h / 2);
    p.closePath();
    return p;
}

/** Colourful placeholder used until an image is chosen. */
function gradientTexture(): THREE.Texture {
    const c = document.createElement('canvas');
    c.width = 256;
    c.height = 256;
    const g = c.getContext('2d')!;
    const grad = g.createLinearGradient(0, 0, 256, 256);
    grad.addColorStop(0, '#f6d365');
    grad.addColorStop(0.35, '#fda085');
    grad.addColorStop(0.7, '#a1c4fd');
    grad.addColorStop(1, '#5b7db1');
    g.fillStyle = grad;
    g.fillRect(0, 0, 256, 256);
    const t = new THREE.CanvasTexture(c);
    t.colorSpace = THREE.SRGBColorSpace;
    return t;
}

/** Simple procedural oak planks for the floor. */
function woodTexture(): THREE.Texture {
    const c = document.createElement('canvas');
    c.width = 512;
    c.height = 512;
    const g = c.getContext('2d')!;
    const tones = ['#a98463', '#9c7757', '#b38d6b', '#a17c5c'];
    const plankH = 64;
    for (let row = 0; row < 512 / plankH; row++) {
        let x = -((row * 137) % 256);
        while (x < 512) {
            const len = 180 + ((row * 53 + x) % 160);
            g.fillStyle = tones[(row + Math.floor(x / 97)) % tones.length];
            g.fillRect(x, row * plankH, len, plankH);
            // grain
            g.strokeStyle = 'rgba(60,40,25,0.12)';
            for (let i = 0; i < 5; i++) {
                g.beginPath();
                const y = row * plankH + 8 + i * 11;
                g.moveTo(x, y);
                g.bezierCurveTo(x + len / 3, y + 4, x + (2 * len) / 3, y - 4, x + len, y);
                g.stroke();
            }
            g.fillStyle = 'rgba(40,25,15,0.35)';
            g.fillRect(x, row * plankH, 2, plankH);
            x += len;
        }
        g.fillStyle = 'rgba(40,25,15,0.35)';
        g.fillRect(0, row * plankH, 512, 2);
    }
    const t = new THREE.CanvasTexture(c);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.colorSpace = THREE.SRGBColorSpace;
    t.anisotropy = 8;
    return t;
}

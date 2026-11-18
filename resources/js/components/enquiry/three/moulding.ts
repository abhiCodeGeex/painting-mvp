import * as THREE from 'three';
import type { FrameProfileShape, PreviewFrame } from '../preview-helpers';

/*
 * Real frame mouldings for the 3D preview.
 *
 * A moulding is a 2D cross-section ("profile") swept around the picture opening. Every profile
 * point (d, z) becomes a rectangle grown by d and lifted by z; joining those rectangles gives
 * clean 45° mitred corners for free. Profiles are lists of smooth runs: normals are smoothed
 * inside a run (rounded faces) and kept sharp between runs (steps, edges).
 */

type Pt = [d: number, z: number]; // 0..1 across the face width, 0..1 front-to-back depth
type Profile = Pt[][];

function curve(n: number, f: (t: number) => Pt): Pt[] {
    return Array.from({ length: n + 1 }, (_, i) => f(i / n));
}

const PROFILES: Record<FrameProfileShape, Profile> = {
    flat: [
        [[0, 0], [0, 1]],
        [[0, 1], [1, 1]],
        [[1, 1], [1, 0]],
    ],
    rounded: [
        [[0, 0], [0, 0.55]],
        curve(18, (t) => [0.5 - 0.5 * Math.cos(Math.PI * t), 0.55 + 0.45 * Math.sin(Math.PI * t)]),
        [[1, 0.55], [1, 0]],
    ],
    scoop: [
        [[0, 0], [0, 0.6]],
        curve(14, (t) => [0.85 * t, 0.6 + 0.4 * Math.pow(t, 2.2)]),
        [[0.85, 1], [1, 1]],
        [[1, 1], [1, 0]],
    ],
    stepped: [
        [[0, 0], [0, 0.62]],
        [[0, 0.62], [0.33, 0.62]],
        [[0.33, 0.62], [0.33, 0.81]],
        [[0.33, 0.81], [0.66, 0.81]],
        [[0.66, 0.81], [0.66, 1]],
        [[0.66, 1], [1, 1]],
        [[1, 1], [1, 0]],
    ],
    ornate: [
        [[0, 0], [0, 0.55]],
        // small bead next to the art
        curve(8, (t) => [0.07 - 0.07 * Math.cos(Math.PI * t), 0.55 + 0.09 * Math.sin(Math.PI * t)]),
        // cove
        curve(8, (t) => [0.14 + 0.16 * t, 0.55 + 0.15 * (1 - Math.cos((Math.PI / 2) * t))]),
        // main carved torus
        curve(18, (t) => [0.3 + 0.55 * (0.5 - 0.5 * Math.cos(Math.PI * t)), 0.7 + 0.3 * Math.sin(Math.PI * t)]),
        // outer fillet
        [[0.85, 0.7], [0.85, 0.8]],
        [[0.85, 0.8], [1, 0.8]],
        [[1, 0.8], [1, 0]],
    ],
    // L-shaped tray: a dark shadow gap all around the canvas, then the outer lip.
    floater: [
        [[0, 0], [0, 0.12]],
        [[0, 0.12], [0.5, 0.12]],
        [[0.5, 0.12], [0.5, 1]],
        [[0.5, 1], [1, 1]],
        [[1, 1], [1, 0]],
    ],
};

/**
 * Builds the moulding around an opening of innerW × innerH (metres).
 * faceW = face width, depth = front-to-back depth (metres). The back of the frame is at z = 0.
 */
export function buildMoulding(innerW: number, innerH: number, faceW: number, depth: number, frame: PreviewFrame): THREE.Mesh {
    const profile = PROFILES[frame.profile ?? 'flat'] ?? PROFILES.flat;
    const positions: number[] = [];
    const uvs: number[] = [];
    const indices: number[] = [];

    // Corners of the rectangle at offset d, as [start, end] of each side (top, right, bottom, left).
    const sides = (d: number): [THREE.Vector2, THREE.Vector2][] => {
        const hx = innerW / 2 + d;
        const hy = innerH / 2 + d;
        return [
            [new THREE.Vector2(-hx, hy), new THREE.Vector2(hx, hy)],
            [new THREE.Vector2(hx, hy), new THREE.Vector2(hx, -hy)],
            [new THREE.Vector2(hx, -hy), new THREE.Vector2(-hx, -hy)],
            [new THREE.Vector2(-hx, -hy), new THREE.Vector2(-hx, hy)],
        ];
    };

    let v = 0; // running length across the profile, for texture v
    for (const run of profile) {
        const runV: number[] = [];
        for (let j = 0; j < run.length; j++) {
            if (j > 0) {
                const [d0, z0] = run[j - 1];
                const [d1, z1] = run[j];
                v += Math.hypot((d1 - d0) * faceW, (z1 - z0) * depth);
            }
            runV.push(v);
        }

        for (let s = 0; s < 4; s++) {
            const base = positions.length / 3;
            for (let j = 0; j < run.length; j++) {
                const [d, z] = run[j];
                const [start, end] = sides(d * faceW)[s];
                const length = start.distanceTo(end);
                // u runs along the side (per metre), centred so the pattern meets nicely at the mitres
                positions.push(start.x, start.y, z * depth, end.x, end.y, z * depth);
                uvs.push(-length / 2, runV[j], length / 2, runV[j]);
            }
            for (let j = 0; j < run.length - 1; j++) {
                const a = base + j * 2;
                indices.push(a, a + 1, a + 3, a, a + 3, a + 2);
            }
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();

    const mesh = new THREE.Mesh(geometry, mouldingMaterial(frame));
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

const FINISHES: Record<string, { roughness: number; metalness: number }> = {
    matte: { roughness: 0.85, metalness: 0 },
    satin: { roughness: 0.5, metalness: 0.05 },
    gloss: { roughness: 0.18, metalness: 0.05 },
    metallic: { roughness: 0.32, metalness: 0.85 },
};

function mouldingMaterial(frame: PreviewFrame): THREE.MeshStandardMaterial {
    const finish = FINISHES[frame.finish] ?? FINISHES['matte'];
    const material = new THREE.MeshStandardMaterial({
        color: frame.colour,
        roughness: finish.roughness,
        metalness: finish.metalness,
        side: THREE.DoubleSide,
    });
    const wooden = (frame.material ?? '').toLowerCase().includes('wood') && frame.finish !== 'metallic';
    if (wooden) {
        material.map = grainTexture();
    }
    if (frame.profile === 'ornate') {
        material.bumpMap = carvingTexture();
        material.bumpScale = 1.2;
    }
    return material;
}

let grain: THREE.Texture | null = null;
let carving: THREE.Texture | null = null;

/** Light wood grain running along the moulding; tinted by the frame colour. */
function grainTexture(): THREE.Texture {
    if (grain) return grain;
    const c = document.createElement('canvas');
    c.width = 512;
    c.height = 64;
    const g = c.getContext('2d')!;
    g.fillStyle = '#ffffff';
    g.fillRect(0, 0, 512, 64);
    for (let i = 0; i < 70; i++) {
        const y = (i * 37) % 64;
        g.strokeStyle = `rgba(0,0,0,${0.04 + ((i * 13) % 10) / 120})`;
        g.lineWidth = 1 + ((i * 7) % 3);
        g.beginPath();
        g.moveTo(0, y);
        g.bezierCurveTo(170, y + ((i % 5) - 2) * 2, 340, y - ((i % 3) - 1) * 3, 512, y);
        g.stroke();
    }
    const t = new THREE.CanvasTexture(c);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    t.colorSpace = THREE.SRGBColorSpace;
    // u is in metres: one grain image per 50 cm; v across the profile.
    t.repeat.set(2, 40);
    grain = t;
    return t;
}

/** Repeating beads and leaves for ornate frames (used as a bump map). */
function carvingTexture(): THREE.Texture {
    if (carving) return carving;
    const c = document.createElement('canvas');
    c.width = 256;
    c.height = 256;
    const g = c.getContext('2d')!;
    g.fillStyle = '#000';
    g.fillRect(0, 0, 256, 256);
    for (let x = 0; x < 256; x += 32) {
        for (let y = 0; y < 256; y += 64) {
            const bead = g.createRadialGradient(x + 16, y + 16, 2, x + 16, y + 16, 14);
            bead.addColorStop(0, '#fff');
            bead.addColorStop(1, '#000');
            g.fillStyle = bead;
            g.beginPath();
            g.arc(x + 16, y + 16, 14, 0, Math.PI * 2);
            g.fill();
            // a small leaf between beads
            g.fillStyle = 'rgba(255,255,255,0.6)';
            g.beginPath();
            g.ellipse(x + 16, y + 46, 6, 12, 0.6, 0, Math.PI * 2);
            g.fill();
        }
    }
    const t = new THREE.CanvasTexture(c);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    // ~3 cm per bead along the moulding.
    t.repeat.set(1 / 0.24, 1 / 0.05);
    carving = t;
    return t;
}

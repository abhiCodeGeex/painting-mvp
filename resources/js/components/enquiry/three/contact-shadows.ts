import * as THREE from 'three';
import { HorizontalBlurShader } from 'three/examples/jsm/shaders/HorizontalBlurShader.js';
import { VerticalBlurShader } from 'three/examples/jsm/shaders/VerticalBlurShader.js';

/**
 * Soft contact shadows on the floor — a port of drei's <ContactShadows>: the scene is rendered
 * from below into a depth texture, blurred twice, and shown on a transparent floor plane.
 */
export class ContactShadows {
    readonly group = new THREE.Group();
    private renderTarget: THREE.WebGLRenderTarget;
    private renderTargetBlur: THREE.WebGLRenderTarget;
    private shadowCamera: THREE.OrthographicCamera;
    private blurPlane: THREE.Mesh;
    private depthMaterial: THREE.MeshDepthMaterial;
    private horizontal: THREE.ShaderMaterial;
    private vertical: THREE.ShaderMaterial;
    private plane: THREE.Mesh<THREE.PlaneGeometry, THREE.MeshBasicMaterial>;

    constructor(
        width: number,
        height: number,
        private opts: { opacity: number; blur: number; far: number; resolution: number },
    ) {
        const { resolution, far } = opts;
        this.renderTarget = new THREE.WebGLRenderTarget(resolution, resolution);
        this.renderTarget.texture.generateMipmaps = false;
        this.renderTargetBlur = new THREE.WebGLRenderTarget(resolution, resolution);
        this.renderTargetBlur.texture.generateMipmaps = false;

        const geometry = new THREE.PlaneGeometry(width, height).rotateX(Math.PI / 2);
        this.blurPlane = new THREE.Mesh(geometry);
        this.blurPlane.visible = false;

        this.depthMaterial = new THREE.MeshDepthMaterial();
        this.depthMaterial.depthTest = false;
        this.depthMaterial.depthWrite = false;
        this.depthMaterial.onBeforeCompile = (shader) => {
            shader.uniforms['ucolor'] = { value: new THREE.Color('#000000') };
            shader.fragmentShader = shader.fragmentShader.replace('void main() {', 'uniform vec3 ucolor;\nvoid main() {');
            shader.fragmentShader = shader.fragmentShader.replace(
                'vec4( vec3( 1.0 - fragCoordZ ), opacity );',
                'vec4( ucolor * fragCoordZ * 2.0, ( 1.0 - fragCoordZ ) * 1.0 );',
            );
        };

        this.horizontal = new THREE.ShaderMaterial(HorizontalBlurShader);
        this.horizontal.depthTest = false;
        this.vertical = new THREE.ShaderMaterial(VerticalBlurShader);
        this.vertical.depthTest = false;

        this.group.rotation.x = Math.PI / 2;
        this.plane = new THREE.Mesh(
            geometry,
            new THREE.MeshBasicMaterial({ transparent: true, map: this.renderTarget.texture, opacity: opts.opacity, depthWrite: false }),
        );
        this.plane.scale.set(1, -1, 1);
        this.plane.rotation.set(-Math.PI / 2, 0, 0);
        this.group.add(this.plane);

        this.shadowCamera = new THREE.OrthographicCamera(-width / 2, width / 2, height / 2, -height / 2, 0, far);
        this.group.add(this.shadowCamera);
        this.group.add(this.blurPlane);
    }

    /** Re-render the shadow texture (call before rendering the main scene). */
    update(renderer: THREE.WebGLRenderer, scene: THREE.Scene): void {
        const background = scene.background;
        const override = scene.overrideMaterial;
        scene.background = null;
        this.group.visible = false;
        scene.overrideMaterial = this.depthMaterial;
        renderer.setRenderTarget(this.renderTarget);
        renderer.render(scene, this.shadowCamera);
        this.group.visible = true;
        this.blur(renderer, this.opts.blur);
        this.blur(renderer, this.opts.blur * 0.4);
        renderer.setRenderTarget(null);
        scene.overrideMaterial = override;
        scene.background = background;
    }

    private blur(renderer: THREE.WebGLRenderer, amount: number): void {
        this.blurPlane.visible = true;
        this.blurPlane.material = this.horizontal;
        this.horizontal.uniforms['tDiffuse'].value = this.renderTarget.texture;
        this.horizontal.uniforms['h'].value = amount / 256;
        renderer.setRenderTarget(this.renderTargetBlur);
        renderer.render(this.blurPlane, this.shadowCamera);

        this.blurPlane.material = this.vertical;
        this.vertical.uniforms['tDiffuse'].value = this.renderTargetBlur.texture;
        this.vertical.uniforms['v'].value = amount / 256;
        renderer.setRenderTarget(this.renderTarget);
        renderer.render(this.blurPlane, this.shadowCamera);
        this.blurPlane.visible = false;
    }

    dispose(): void {
        this.renderTarget.dispose();
        this.renderTargetBlur.dispose();
        this.depthMaterial.dispose();
        this.horizontal.dispose();
        this.vertical.dispose();
        this.plane.geometry.dispose();
        this.plane.material.dispose();
    }
}

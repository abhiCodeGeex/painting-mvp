<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Frame photo studio</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; background: #fafaf9; color: #1c1917; }
        #gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-top: 16px; }
        #gallery img { width: 100%; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.12); }
        pre { background: #fff; border: 1px solid #e7e5e4; padding: 12px; border-radius: 8px; }
    </style>
    <script type="application/json" id="studio-data">@json(['frames' => $frames, 'csrf' => csrf_token()])</script>
    @vite('resources/js/tools/frame-studio.ts')
</head>
<body>
    <h1>Frame photo studio</h1>
    <p>Rendering a corner sample photo for each frame with the 3D moulding. Photos are saved to the frames automatically.</p>
    <pre id="log"></pre>
    <div id="gallery"></div>
</body>
</html>

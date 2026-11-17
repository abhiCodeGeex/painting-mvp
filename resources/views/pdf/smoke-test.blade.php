<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>PDF smoke test</title>
    <style>
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; padding: 32px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { margin: 4px 0; }
        .amount { font-size: 28px; font-weight: 700; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>{{ $company }} — PDF rendering works</h1>
    <p>Generated {{ $generatedAt }}</p>
    <p class="amount">{{ $amount }}</p>
</body>
</html>

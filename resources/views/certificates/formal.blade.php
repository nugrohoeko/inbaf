<!doctype html>
@php
    $portrait = ($payload['orientation'] ?? 'landscape') === 'portrait';
    $canvasHeight = $portrait ? (($payload['paper'] ?? 'a4') === 'letter' ? 241 : 259) : 171;
    $competencyRows = array_chunk($payload['competency_scores'], $portrait ? 4 : 7);
@endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payload['number'] }}</title>
    <style>
        @page { margin: 10mm; }
        body { margin: 0; color: #101F43; font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; }
        .canvas { position: relative; height: {{ $canvasHeight }}mm; padding: 7mm 11mm; border: 3px double #a58b51; text-align: center; }
        .background { position: absolute; top: 0; right: 0; bottom: 0; left: 0; background-position: center; background-size: cover; background-repeat: no-repeat; opacity: 0.08; }
        .content { position: relative; z-index: 1; }
        .institution { text-transform: uppercase; letter-spacing: 2px; font-size: 10pt; }
        .brand { font-size: 16pt; font-weight: bold; letter-spacing: 5px; margin: 2mm 0; }
        .brand img { height: 12mm; vertical-align: middle; margin-right: 3mm; }
        .subtitle { color: #64748B; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 2px; }
        .ornament { border-top: 1px solid #a58b51; width: 62mm; margin: 3mm auto; }
        h1 { font-family: 'DejaVu Serif', serif; font-weight: normal; font-size: 25pt; color: {{ $payload['primary_color'] }}; margin: 1mm 0 3mm; }
        .intro { color: #64748B; font-size: 8pt; margin: 0 0 2mm; }
        .name { font-family: 'DejaVu Serif', serif; font-size: {{ mb_strlen($payload['holder_name']) > 36 ? '22' : '28' }}pt; font-weight: bold; margin: 0 0 4mm; }
        .description { margin: 0; font-size: 8.5pt; line-height: 1.7; }
        table { border-collapse: collapse; width: 100%; }
        .results { margin: 4mm 0 2mm; border-top: 1px solid #d9cfb8; border-bottom: 1px solid #d9cfb8; }
        .results td { padding: 3mm; width: 33.33%; }
        .label { color: #64748B; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 1px; }
        .value { font-family: 'DejaVu Serif', serif; font-size: 19pt; margin-top: 1mm; }
        .competencies td { padding: 2mm 1mm; font-size: 6.5pt; color: #64748B; }
        .competency-score { font-size: 10pt; color: #101F43; margin-top: 1mm; }
        .footer { position: absolute; bottom: 7mm; left: 11mm; right: 11mm; text-align: left; z-index: 1; }
        .authorized-signature { height: 12mm; max-width: 50mm; margin-bottom: 1mm; }
        .small { color: #64748B; font-size: 6.5pt; line-height: 1.6; }
        .signature { border-top: 1px solid #a58b51; padding-top: 2mm; margin-top: 5mm; font-weight: bold; font-size: 10pt; }
        .qr { width: 28mm; height: 28mm; }
        .notice { font-size: 5.5pt; color: #64748B; line-height: 1.5; margin-top: 3mm; text-align:center; }
        .status { font-size: 10pt; color: #b91c1c; font-weight: bold; }
        .portrait .institution { font-size: 9pt; }
        .portrait .brand { margin-top: 8mm; }
        .portrait .subtitle { font-size: 5.5pt; letter-spacing: 1px; }
        .portrait .ornament { margin: 10mm auto; }
        .portrait h1 { font-size: 22pt; margin-bottom: 10mm; }
        .portrait .name { font-size: {{ mb_strlen($payload['holder_name']) > 36 ? 21 : 26 }}pt; margin-bottom: 8mm; }
        .portrait .results { margin-top: 9mm; margin-bottom: 7mm; }
        .portrait .results td { padding: 4mm 2mm; }
        .portrait .value { font-size: 17pt; }
        .portrait .competencies td { padding: 3mm 1mm; }
        .portrait .label { font-size: 5.5pt; }
        .portrait .small { font-size: 5.5pt; }
        .portrait .signature { font-size: 8.5pt; }
    </style>
</head>
<body class="{{ $portrait ? 'portrait' : 'landscape' }}">
<div class="canvas">
    @if($backgroundDataUri)<div class="background" style="background-image:url('{{ $backgroundDataUri }}')"></div>@endif
    <div class="content">
    <div class="institution">{{ $payload['institution'] }}</div>
    <div class="brand">@if($logoDataUri)<img src="{{ $logoDataUri }}" alt="INBAF">@endif INBAF</div>
    <div class="subtitle">Indonesian Language Assessment Platform for Foreign Students</div>
    <div class="ornament"></div>
    <h1>Certificate of Language Proficiency</h1>
    <p class="intro">This certificate is awarded to</p>
    <p class="name">{{ $payload['holder_name'] }}</p>
    <p class="description">for successfully completing <strong>{{ $payload['exam_package'] }}</strong><br>on {{ \Carbon\Carbon::parse($payload['test_date'])->format('d F Y') }}.</p>
    <table class="results"><tr>
        <td><div class="label">Overall score</div><div class="value">{{ number_format($payload['total_score'], 1) }} / {{ $payload['score_max'] }}</div></td>
        <td><div class="label">INBAF proficiency level</div><div class="value">{{ $payload['level'] }}</div></td>
        <td><div class="label">Certificate number</div><div style="font-size:10pt;margin-top:3mm">{{ $payload['number'] }}</div></td>
    </tr></table>
    @if(count($payload['competency_scores']))
    <table class="competencies">@foreach($competencyRows as $row)<tr>@foreach($row as $competency)<td>{{ $competency['name'] ?? $competency['code'] ?? 'Competency' }}<div class="competency-score">{{ number_format($competency['score'] ?? 0, 1) }}</div></td>@endforeach</tr>@endforeach</table>
    @endif
    </div>
    <div class="footer">
        <table><tr>
            <td style="width:40%;vertical-align:bottom;padding-right:10mm">@if($signatureDataUri)<img class="authorized-signature" src="{{ $signatureDataUri }}" alt="Authorized signature">@endif<div class="signature" @if($signatureDataUri) style="margin-top:0" @endif>{{ $payload['signer_name'] }}</div><div class="small">{{ $payload['signer_title'] }}</div><div class="small">{{ $signatureDataUri ? 'Authorized institutional signature' : 'Authenticated through signed INBAF records' }}</div></td>
            <td style="width:42%;vertical-align:bottom"><div class="small">Date of issue: {{ \Carbon\Carbon::parse($payload['issued_at'])->format('d F Y') }}</div><div class="small">{{ $payload['expires_at'] ? 'Valid until: '.\Carbon\Carbon::parse($payload['expires_at'])->format('d F Y') : 'No expiry date' }}</div>@if($status !== 'valid')<div class="status">{{ strtoupper($status) }}</div>@endif</td>
            <td style="width:18%;text-align:right"><a href="{{ $verificationUrl }}"><img class="qr" src="{{ $qrDataUri }}" alt="Scan to verify"></a><div class="small">Verify authenticity</div></td>
        </tr></table>
        <div class="notice">{{ $payload['qualification'] }} Check current status using the QR code. This is a reproduction of the signed certificate record, not a qualified electronic signature.</div>
    </div>
</div>
</body>
</html>

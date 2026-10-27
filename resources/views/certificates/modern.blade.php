<!doctype html>
@php
    $portrait = ($payload['orientation'] ?? 'landscape') === 'portrait';
    $canvasHeight = $portrait ? (($payload['paper'] ?? 'a4') === 'letter' ? 239 : 257) : 170;
    $competencyRows = array_chunk($payload['competency_scores'], $portrait ? 4 : 7);
@endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payload['number'] }}</title>
    <style>
        @page { margin: 10mm; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #101F43; font-size: 9pt; }
        .canvas { position: relative; height: {{ $canvasHeight }}mm; padding: 8mm 10mm; border: 1px solid #d7dfeb; border-top: 5px solid {{ $payload['primary_color'] }}; background: #ffffff; }
        .background { position: absolute; top: 0; right: 0; bottom: 0; left: 0; background-position: center; background-size: cover; background-repeat: no-repeat; opacity: 0.08; }
        .content { position: relative; z-index: 1; }
        table { width: 100%; border-collapse: collapse; }
        .brand { font-size: 23pt; font-weight: bold; letter-spacing: 3px; }
        .brand img { height: 12mm; vertical-align: middle; margin-right: 3mm; }
        .small { color: #64748B; font-size: 7pt; }
        .eyebrow { font-size: 8pt; letter-spacing: 3px; color: #376BFF; text-transform: uppercase; margin: 7mm 0 2mm; }
        h1 { font-size: 29pt; margin: 0; line-height: 1.2; font-weight: normal; }
        .recipient { font-size: 9pt; color: #64748B; margin: 4mm 0 1mm; }
        .name { font-size: {{ mb_strlen($payload['holder_name']) > 36 ? '23' : '30' }}pt; font-weight: bold; margin: 0 0 3mm; line-height: 1.2; }
        .description { font-size: 9pt; margin: 0; color: #475569; line-height: 1.6; }
        .results { margin-top: 4mm; }
        .results td { background: #f1f5fc; padding: 4mm 5mm; width: 33.33%; border-right: 3px solid white; }
        .value { font-size: 21pt; font-weight: bold; color: {{ $payload['primary_color'] }}; }
        .label { text-transform: uppercase; color: #64748B; font-size: 6.5pt; letter-spacing: 1px; }
        .competencies { margin-top: 3mm; }
        .competencies td { text-align: center; padding: 2mm 1mm; }
        .competency-name { color: #64748B; font-size: 6pt; }
        .competency-score { font-size: 10pt; font-weight: bold; margin-top: 1mm; }
        .footer { position: absolute; bottom: 8mm; left: 10mm; right: 10mm; z-index: 1; }
        .authorized-signature { height: 12mm; max-width: 52mm; margin-bottom: 1mm; }
        .signature { font-size: 11pt; font-weight: bold; margin-bottom: 1mm; }
        .rule { width: 55mm; border-top: 1px solid #101F43; margin-bottom: 2mm; }
        .qr { width: 28mm; height: 28mm; }
        .notice { font-size: 5.8pt; color: #64748B; margin-top: 4mm; line-height: 1.5; }
        .status { color: #b91c1c; font-weight: bold; font-size: 10pt; }
        .portrait .brand { font-size: 19pt; letter-spacing: 2px; }
        .portrait .eyebrow { margin-top: 20mm; font-size: 7pt; letter-spacing: 2px; }
        .portrait h1 { font-size: 27pt; }
        .portrait .recipient { margin-top: 12mm; }
        .portrait .name { font-size: {{ mb_strlen($payload['holder_name']) > 36 ? 23 : 28 }}pt; margin-bottom: 6mm; }
        .portrait .results { margin-top: 10mm; }
        .portrait .results td { padding: 4mm 3mm; }
        .portrait .value { font-size: 18pt; }
        .portrait .competencies { margin-top: 10mm; }
        .portrait .competencies td { padding: 3mm 1mm; }
        .portrait .label { font-size: 5.8pt; }
        .portrait .small { font-size: 6pt; }
        .portrait .signature { font-size: 9pt; }
        .portrait .rule { width: 45mm; }
    </style>
</head>
<body class="{{ $portrait ? 'portrait' : 'landscape' }}">
<div class="canvas">
    @if($backgroundDataUri)<div class="background" style="background-image:url('{{ $backgroundDataUri }}')"></div>@endif
    <div class="content">
    <table><tr>
        <td class="brand">@if($logoDataUri)<img src="{{ $logoDataUri }}" alt="INBAF">@endif INBAF</td>
        <td style="text-align:right; vertical-align:top"><div class="small">{{ $payload['institution'] }}</div><div class="small" style="margin-top:2mm">{{ $payload['number'] }}</div></td>
    </tr></table>
    <div class="eyebrow">Indonesian Language Assessment</div>
    <h1>Certificate of Proficiency</h1>
    <p class="recipient">This is to certify that</p>
    <p class="name">{{ $payload['holder_name'] }}</p>
    <p class="description">has successfully completed <strong>{{ $payload['exam_package'] }}</strong><br>and demonstrated the following Indonesian language assessment results.</p>
    <table class="results"><tr>
        <td><div class="label">Overall score</div><div class="value">{{ number_format($payload['total_score'], 2) }} <span style="font-size:10pt;color:#64748B">/ {{ $payload['score_max'] }}</span></div></td>
        <td><div class="label">INBAF proficiency level</div><div class="value">{{ $payload['level'] }}</div></td>
        <td><div class="label">Assessment date</div><div style="font-size:13pt;margin-top:2mm">{{ \Carbon\Carbon::parse($payload['test_date'])->format('d F Y') }}</div></td>
    </tr></table>
    @if(count($payload['competency_scores']))
    <table class="competencies">@foreach($competencyRows as $row)<tr>
        @foreach($row as $competency)
            <td><div class="competency-name">{{ $competency['name'] ?? $competency['code'] ?? 'Competency' }}</div><div class="competency-score">{{ number_format($competency['score'] ?? 0, 2) }}</div></td>
        @endforeach
    </tr>@endforeach</table>
    @endif
    </div>
    <div class="footer">
        <table><tr>
            <td style="width:43%;vertical-align:bottom">@if($signatureDataUri)<img class="authorized-signature" src="{{ $signatureDataUri }}" alt="Authorized signature">@endif<div class="rule"></div><div class="signature">{{ $payload['signer_name'] }}</div><div class="small">{{ $payload['signer_title'] }}</div><div class="small">{{ $signatureDataUri ? 'Authorized institutional signature' : 'Digitally authenticated by INBAF verification' }}</div></td>
            <td style="width:39%;vertical-align:bottom"><div class="small">Issued {{ \Carbon\Carbon::parse($payload['issued_at'])->format('d F Y') }}</div><div class="small">{{ $payload['expires_at'] ? 'Valid until '.\Carbon\Carbon::parse($payload['expires_at'])->format('d F Y') : 'No expiry date' }}</div>@if($status !== 'valid')<div class="status">{{ strtoupper($status) }}</div>@endif</td>
            <td style="width:18%;text-align:right"><a href="{{ $verificationUrl }}"><img class="qr" src="{{ $qrDataUri }}" alt="Scan to verify"></a><div class="small">Scan to verify</div></td>
        </tr></table>
        <div class="notice">{{ $payload['qualification'] }} Authenticity and current status must be checked through the QR code. This PDF reproduces the signed certificate record; it is not a qualified electronic signature.</div>
    </div>
</div>
</body>
</html>

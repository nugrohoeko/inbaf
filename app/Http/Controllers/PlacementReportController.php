<?php

namespace App\Http\Controllers;

use App\Models\ExamAttempt;
use App\Support\Audit;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

class PlacementReportController extends Controller
{
    public function show(Request $request, ExamAttempt $attempt)
    {
        abort_unless($attempt->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        abort_unless($attempt->test_type === 'placement', 404);
        abort_unless($attempt->status === 'completed', 409, 'Laporan tersedia setelah penilaian selesai.');
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.placement', ['attempt' => $attempt, 'result' => $attempt->result])->render());
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();
        Audit::record('placement_report.downloaded', $attempt);
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="INBAF-placement-'.$attempt->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

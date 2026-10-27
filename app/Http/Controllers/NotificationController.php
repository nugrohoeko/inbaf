<?php

namespace App\Http\Controllers;

use App\Models\{Certificate, ExamAnswer, ExamAttempt};
use App\Services\CertificateService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $items = [];
        if ($user->isAdmin()) {
            $reviews = ExamAnswer::where('requires_manual', true)->whereNull('manual_score')->whereHas('attempt', fn ($query) => $query->where('status', 'pending_review'))->count();
            if ($reviews) $items[] = ['id' => 'reviews', 'type' => 'admin_reviews', 'count' => $reviews, 'detail' => null, 'url' => '/admin/reviews'];
            $active = ExamAttempt::where('status', 'in_progress')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
            if ($active) $items[] = ['id' => 'active-attempts', 'type' => 'admin_active', 'count' => $active, 'detail' => null, 'url' => '/dashboard'];
        } else {
            foreach (ExamAttempt::where('user_id', $user->id)->whereIn('status', ['in_progress', 'pending_review', 'completed'])->latest('updated_at')->limit(10)->get() as $attempt) {
                $items[] = ['id' => 'attempt:'.$attempt->id, 'type' => $attempt->status, 'count' => null, 'detail' => $attempt->snapshot['package']['name'] ?? 'INBAF', 'url' => ($attempt->status === 'in_progress' ? '/exam/' : '/results/').$attempt->id, 'occurred_at' => $attempt->updated_at->toISOString()];
            }
            foreach (Certificate::where('user_id', $user->id)->latest('updated_at')->limit(5)->get() as $certificate) {
                $status = app(CertificateService::class)->status($certificate);
                if ($status === 'not_found') continue;
                $items[] = ['id' => 'certificate:'.$certificate->id, 'type' => 'certificate_'.$status, 'count' => null, 'detail' => $certificate->payload['exam_package'] ?? 'INBAF', 'url' => '/certificates', 'occurred_at' => $certificate->updated_at->toISOString()];
            }
            usort($items, fn ($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));
        }

        // Current activity, rather than an invented unread count or a persisted delivery claim.
        return response()->json(['items' => array_slice($items, 0, 10)])->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, ExamAttempt, ExamPackage, MediaAsset, SystemSetting};
use App\Services\AttemptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttemptController extends Controller
{
    public function __construct(private readonly AttemptService $service) {}

    private function authorizeAttempt(Request $request, ExamAttempt $attempt, bool $write = false): void
    {
        abort_unless($request->user()->active, 403, 'Akun tidak aktif.');
        abort_unless($attempt->user_id === $request->user()->id || (! $write && $request->user()->isAdmin()), 403);
    }

    public function store(Request $request, ExamPackage $package)
    {
        $data = $request->validate(['access_code' => ['nullable', 'string', 'max:255']]);
        $attempt = $this->service->start($request->user(), $package, $data['access_code'] ?? null);
        return response()->json($this->service->payload($attempt), 201);
    }

    public function show(Request $request, ExamAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt);
        return response()->json($this->service->payload($attempt));
    }

    public function answer(Request $request, ExamAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt, true);
        $data = $request->validate(['question_key' => ['required', 'string', 'max:100'], 'response' => ['present', 'nullable'], 'marked' => ['sometimes', 'boolean']]);
        abort_if(strlen(json_encode($data['response'])) > 100000, 422, 'Jawaban terlalu panjang.');
        $attempt = $this->service->expire($attempt);
        $answer = $this->service->save($attempt, $data['question_key'], $data['response'], $data['marked'] ?? null);
        return response()->json(['answer' => $answer, 'saved_at' => now()->toISOString(), 'server_time' => now()->toISOString()]);
    }

    public function activateQuestion(Request $request, ExamAttempt $attempt, string $key)
    {
        $this->authorizeAttempt($request, $attempt, true);
        abort_if(mb_strlen($key) > 100, 422, 'Invalid question key.');
        $attempt = $this->service->expire($attempt);
        $answer = $this->service->activate($attempt, $key);

        return response()->json([
            'answer' => $answer,
            'question_started_at' => $answer->question_started_at?->toISOString(),
            'question_expires_at' => $answer->question_expires_at?->toISOString(),
            'server_time' => now()->toISOString(),
        ]);
    }

    public function submit(Request $request, ExamAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt, true);
        $attempt = $this->service->submit($attempt);
        return response()->json(ParticipantController::publicData(['attempt' => $attempt, 'result' => $attempt->result, 'certificate' => $attempt->certificate]));
    }

    public function event(Request $request, ExamAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt, true);
        $data = $request->validate(['type' => ['required', 'in:tab_hidden,tab_visible,fullscreen_exit,fullscreen_enter,connection_lost,connection_restored,copy,paste,session_resumed,section_started,section_closed'], 'metadata' => ['nullable', 'array']]);
        abort_unless($attempt->status === 'in_progress', 409, 'Ujian telah berakhir.');
        abort_if(strlen(json_encode($data['metadata'] ?? [])) > 5000, 422);
        if (!($attempt->snapshot['rules']['security_monitoring'] ?? true)) return response()->json(['recorded' => false]);
        AuditLog::create(['user_id' => $request->user()->id, 'event' => 'exam.'.$data['type'], 'subject_type' => 'exam_attempt', 'subject_id' => $attempt->id, 'data' => $data['metadata'] ?? [], 'ip' => $request->ip()]);
        $switches = AuditLog::where('subject_type', 'exam_attempt')->where('subject_id', $attempt->id)->where('event', 'exam.tab_hidden')->count();
        $limit = (int) ($attempt->snapshot['rules']['tab_switch_limit'] ?? 3);
        return response()->json(['recorded' => true, 'tab_switch_count' => $switches, 'tab_switch_limit' => $limit, 'limit_exceeded' => $switches > $limit]);
    }

    public function recording(Request $request, ExamAttempt $attempt)
    {
        $this->authorizeAttempt($request, $attempt, true);
        $max = min(102400, max(1024, (int) SystemSetting::getValue('max_upload_mb', 25) * 1024));
        $data = $request->validate(['question_key' => ['required', 'string', 'max:100'], 'file' => ['required', 'file', 'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mpeg,audio/mp4,audio/x-m4a,video/mp4,audio/wav,audio/x-wav', 'max:'.$max], 'duration' => ['nullable', 'numeric', 'min:0', 'max:3600']]);
        $attempt = $this->service->expire($attempt);
        [, $question] = $this->service->assertWritable($attempt, $data['question_key']);
        abort_unless($question['type'] === 'speaking', 422, 'Rekaman hanya diizinkan untuk soal speaking.');
        $file = $request->file('file');
        $path = $file->store('recordings/'.$request->user()->id.'/'.$attempt->id, 'local');
        $media = null;
        try {
            $media = MediaAsset::create(['original_name' => basename($file->getClientOriginalName()), 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'kind' => 'audio', 'metadata' => ['user_id' => $request->user()->id, 'exam_attempt_id' => $attempt->id, 'question_key' => $data['question_key'], 'duration' => $data['duration'] ?? null, 'private' => true]]);
            $response = ['media_asset_id' => $media->id, 'url' => url('/api/media/'.$media->id), 'duration' => $data['duration'] ?? null];
            $answer = $this->service->save($attempt, $data['question_key'], $response);
            return response()->json(['answer' => $answer, 'recording' => $response], 201);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            $media?->delete();
            throw $exception;
        }
    }
}

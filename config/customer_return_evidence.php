<?php

return [
    // Application limits must also fit the PHP/web-server upload limits.
    'max_photo_kib' => (int) env('RETURN_EVIDENCE_MAX_PHOTO_KIB', 16384),
    'max_video_kib' => (int) env('RETURN_EVIDENCE_MAX_VIDEO_KIB', 102400),
    'max_video_seconds' => (int) env('RETURN_EVIDENCE_MAX_VIDEO_SECONDS', 120),
    // Enable only after the separately approved FFmpeg installation is verified.
    'ffmpeg_binary' => env('RETURN_EVIDENCE_FFMPEG_BINARY', ''),
];

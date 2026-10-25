<?php

return [
    // Photos still use the normal request limit. Videos travel in verified
    // 5 MiB parts, so this full-file limit need not fit PHP's POST limit.
    'max_photo_kib' => (int) env('RETURN_EVIDENCE_MAX_PHOTO_KIB', 16384),
    'max_video_kib' => (int) env('RETURN_EVIDENCE_MAX_VIDEO_KIB', 204800),
    'max_video_seconds' => (int) env('RETURN_EVIDENCE_MAX_VIDEO_SECONDS', 300),
    // Enable only after the separately approved FFmpeg installation is verified.
    'ffmpeg_binary' => env('RETURN_EVIDENCE_FFMPEG_BINARY', ''),
];

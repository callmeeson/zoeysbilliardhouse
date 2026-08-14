<?php
// SPA entry point — serves the built Vue frontend.
// Authentication is handled by the SPA via the /api endpoints.
if (!is_file(__DIR__ . '/dist/index.html')) {
    http_response_code(500);
    exit('Frontend not built yet. Run "npm run build" in the project folder.');
}
readfile(__DIR__ . '/dist/index.html');
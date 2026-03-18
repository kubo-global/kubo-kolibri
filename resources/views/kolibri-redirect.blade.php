<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opening exercise...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: #f9fafb;
            color: #374151;
        }
        .container { text-align: center; padding: 2rem; }
        .spinner {
            width: 48px; height: 48px;
            border: 4px solid #e5e7eb;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1.5rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .fallback { display: none; margin-top: 1.5rem; }
        .fallback a {
            display: inline-block;
            padding: 0.75rem 2rem;
            background: #2563eb;
            color: white;
            border-radius: 0.5rem;
            text-decoration: none;
            font-weight: 500;
        }
        .fallback a:hover { background: #1d4ed8; }
        .fallback .hint { font-size: 0.875rem; color: #6b7280; margin-top: 0.75rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="spinner" id="spinner"></div>
        <p id="status">Signing you into Kolibri...</p>

        <div class="fallback" id="fallback">
            <a href="{{ $content_url }}">Continue to exercise</a>
            <p class="hint">You may need to select your name in Kolibri the first time.</p>
        </div>
    </div>

    <script>
        (function() {
            var sessionUrl = @json($session_url);
            var contentUrl = @json($content_url);
            var credentials = {
                username: @json($username),
                password: @json($password),
                facility: @json($facility_id)
            };

            // Try to authenticate the student into Kolibri via its API.
            // This works when Kolibri allows cross-origin requests (e.g. same
            // local network with CORS configured). If it fails, we show a
            // direct link — the student just has to pick their name once.
            fetch(sessionUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify(credentials)
            })
            .then(function(response) {
                if (response.ok) {
                    window.location.href = contentUrl;
                } else {
                    showFallback();
                }
            })
            .catch(function() {
                showFallback();
            });

            // If auto-login hasn't redirected within 5 seconds, show fallback
            setTimeout(function() {
                showFallback();
            }, 5000);

            function showFallback() {
                document.getElementById('spinner').style.display = 'none';
                document.getElementById('status').textContent = 'Could not auto-sign in.';
                document.getElementById('fallback').style.display = 'block';
            }
        })();
    </script>
</body>
</html>

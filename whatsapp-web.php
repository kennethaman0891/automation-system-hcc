<?php
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en" class="wa-standalone-page">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES); ?>">
  <title>HCC — WhatsApp Hub</title>
  <meta name="description" content="HCC WhatsApp Web Replica — Real-time messaging, chat history, media support">

  <!-- WhatsApp Web Design System CSS -->
  <link rel="stylesheet" href="/assets/css/whatsapp-web.css">

  <style>
    html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
    #root { width: 100vw; height: 100vh; }
    ::-webkit-scrollbar { width: 0; height: 0; }
  </style>
</head>
<body class="wa-standalone-page">
  <div id="root">
    <div id="wa-loading" style="display:flex;align-items:center;justify-content:center;height:100vh;background:var(--wa-bg-chat-list-var)">
      <wa-spinner class="wa-spinner-lg"></wa-spinner>
      <div style="margin-left:12px;color:var(--wa-text-secondary-var)">Loading WhatsApp Web...</div>
    </div>
  </div>

  <!-- Audio Synthesizer & Notifications Engine -->
  <script src="assets/js/whatsapp-audio-notifications.js"></script>
  <!-- Central store (must load before components) -->
  <script src="assets/js/whatsapp-store.js"></script>
  <script src="/assets/js/whatsapp-store.js"></script>
  <!-- Components (load after store) -->
  <script src="/assets/js/whatsapp-components.js"></script>
  <script src="/assets/js/whatsapp-components2.js"></script>
  <script src="/assets/js/whatsapp-ai-assistant.js"></script>

  <script>
    (function() {
      var checkStore = function(cb) {
        if (window.WhatsAppStore && window.WhatsAppStore.db) {
          cb();
        } else {
          setTimeout(function() { checkStore(cb); }, 50);
        }
      };

      document.addEventListener('DOMContentLoaded', function() {
        checkStore(function() {
          var loading = document.getElementById('wa-loading');
          if (loading) loading.style.display = 'none';

          var root = document.getElementById('root');
          root.innerHTML = '<wa-app></wa-app>';
        });
      });
    })();
  </script>
</body>
</html>

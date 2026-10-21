</main>
<!-- RF13: Assistente Virtual SENN AI-X Helper -->
<button id="chat-btn" class="chat-btn" aria-controls="chat" aria-expanded="false">💬 SENN AI-X Helper</button>
<section id="chat" class="chat" hidden aria-label="Assistente virtual">
  <header><strong>SENN AI-X Helper</strong><button id="chat-fechar" aria-label="Fechar chat">✕</button></header>
  <div id="chat-msgs" class="chat-msgs" aria-live="polite">
    <div class="msg bot">Olá! Pergunte sobre entregas, indicadores ou como usar o sistema. Ex.: "quantas entregas atrasadas?", "status do pedido PED-1001".</div>
  </div>
  <form id="chat-form">
    <label for="chat-txt" class="sr">Sua pergunta</label>
    <input id="chat-txt" autocomplete="off" placeholder="Digite sua pergunta..." required>
    <button>Enviar</button>
  </form>
</section>
<script src="assets/js/app.js"></script>
</body>
</html>

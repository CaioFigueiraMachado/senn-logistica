  </main>
</div>
</div>
<!-- RF13: Assistente Virtual SENN AI-X Helper -->
<button id="chat-btn" class="chat-btn" aria-controls="chat" aria-expanded="false">Precisa de ajuda?</button>
<section id="chat" class="chat" hidden aria-label="Assistente virtual">
  <header><div><strong>SENN AI-X Helper</strong><br><small class="ia-fonte">Assistente da operação</small></div><button id="chat-fechar" aria-label="Fechar chat"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button></header>
  <div id="chat-msgs" class="chat-msgs" aria-live="polite">
    <div class="msg bot">Oi, <?= e(explode(' ', $u['nome'])[0]) ?>! Sou o assistente da SENN AI-X. Posso ver como estão as entregas, os indicadores ou te ajudar a usar o sistema. É só perguntar do seu jeito.</div>
  </div>
  <form id="chat-form">
    <label for="chat-txt" class="sr">Sua pergunta</label>
    <input id="chat-txt" autocomplete="off" placeholder="Digite sua pergunta..." required>
    <button>Enviar</button>
  </form>
</section>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>

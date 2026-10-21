<?php
/**
 * Logo da SENN AI-X em SVG embutido: usa as cores do tema (--pri e --pri-txt),
 * então fica preta no tema claro e branca no escuro, como os botões do site.
 * Desenho: uma rota em "S" saindo do depósito até o pino de entrega.
 */
function logo_svg(string $classe = 'logo'): string {
    return '<svg class="' . $classe . '" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
        . '<rect width="32" height="32" rx="8" fill="var(--pri)"/>'
        . '<g fill="none" stroke="var(--pri-txt)" stroke-width="2.4" stroke-linecap="round">'
        . '<path d="M9 24.5h7.5a3.75 3.75 0 0 0 0-7.5h-1a3.75 3.75 0 0 1 0-7.5H19"/>'
        . '</g>'
        . '<circle cx="8.5" cy="24.5" r="2.6" fill="var(--pri-txt)"/>'
        . '<path d="M22.5 5.2a3.9 3.9 0 0 0-3.9 3.9c0 2.8 3.9 6.4 3.9 6.4s3.9-3.6 3.9-6.4a3.9 3.9 0 0 0-3.9-3.9Z" fill="var(--pri-txt)"/>'
        . '<circle cx="22.5" cy="9.1" r="1.4" fill="var(--pri)"/>'
        . '</svg>';
}

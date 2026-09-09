<?php
// SPDX-License-Identifier: MIT
namespace GlpiPlugin\Pellissarinotification;

final class View
{
    private static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function admin(array $cfg, string $error = '', bool $saved = false): void
    {
        Access::admin();
        $h = static fn(mixed $v): string => self::h($v);
        $check = static fn(string $key): string => !empty($cfg[$key]) ? ' checked' : '';
        $descriptions = [
            'group_assigned' => 'Integrantes diretos do grupo responsável, com perfil técnico e acesso ao chamado.',
            'user_assigned' => 'A pessoa adicionada como técnico responsável pelo chamado.',
            'status_changed' => 'Técnicos diretamente atribuídos, quando o status realmente mudar.',
            'requester_comment' => 'Técnicos diretamente atribuídos, quando um solicitante registrado comentar publicamente.',
        ];
        echo '<main class="pn-admin" id="pn-admin">';
        echo '<header class="pn-admin-hero"><div><div class="pn-eyebrow">CENTRAL DE NOTIFICAÇÕES</div><h1>Pellissari Notification<span>GLPI 11</span></h1><p>O aviso certo, para a pessoa certa.</p></div><span class="pn-version">v0.1.2</span></header>';
        if ($saved) { echo '<div class="pn-banner pn-success" role="status">Configurações salvas. As regras valem para os próximos eventos.</div>'; }
        if ($error !== '') { echo '<div class="pn-banner pn-error" role="alert">' . $h($error) . '</div>'; }
        echo '<form method="post" action="' . $h(Access::base() . '/Config') . '" id="pn-settings">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . $h(\Session::getNewCSRFToken()) . '">';
        echo '<input type="hidden" name="_pellissarinotification_csrf" value="' . $h(Access::csrf()) . '">';
        echo '<section class="pn-config-card"><div class="pn-card-heading"><div><h2>Regras gerais</h2><p>Padrão para todos os técnicos. As exceções individuais ficam logo abaixo.</p></div><label class="pn-switch-label"><input type="checkbox" name="enabled" value="1"' . $check('enabled') . '> Ativar notificações reais</label></div>';
        echo '<div class="pn-rule-grid pn-rule-grid-head"><span>Quando notificar</span><span>Ativa</span><span>Cor do alerta</span><span>Visualizar</span></div>';
        foreach (Settings::TYPES as $type => $label) {
            echo '<div class="pn-rule-grid pn-rule-row" data-rule="' . $h($type) . '"><div class="pn-rule-label"><span class="pn-color-dot" data-dot="' . $h($type) . '" style="background:' . $h($cfg['color_' . $type]) . '"></span><div><strong>' . $h($label) . '</strong><p>' . $h($descriptions[$type]) . '</p></div></div>';
            echo '<label class="pn-check-cell"><input type="checkbox" name="enabled_' . $h($type) . '" value="1" aria-label="Ativar ' . $h($label) . '"' . $check('enabled_' . $type) . '></label>';
            echo '<div class="pn-color-field"><input type="color" name="color_' . $h($type) . '" value="' . $h($cfg['color_' . $type]) . '" aria-label="Cor: ' . $h($label) . '"><code data-hex="' . $h($type) . '">' . $h($cfg['color_' . $type]) . '</code></div>';
            echo '<button class="pn-btn pn-btn-quiet pn-test" type="button" data-type="' . $h($type) . '">Testar para mim</button></div>';
        }
        echo '<p class="pn-help pn-test-note">O teste usa a cor selecionada, mesmo antes de salvar. Aparece apenas para você, inclusive no seu histórico, sem criar ou alterar chamados reais.</p></section>';
        echo '<section class="pn-config-card"><div class="pn-card-heading"><div><h2>Entrega e histórico</h2><p>Ajuste a frequência dos avisos e o tempo de armazenamento.</p></div><span class="pn-tag">Sem serviço externo</span></div><div class="pn-settings-grid">';
        foreach (['poll_interval' => ['Intervalo de consulta', 5, 120, 'segundos'], 'toast_duration' => ['Duração do alerta', 5, 60, 'segundos'], 'retention_days' => ['Guardar histórico por', 7, 365, 'dias']] as $key => [$label, $min, $max, $unit]) {
            echo '<label class="pn-field"><span>' . $h($label) . '</span><div class="pn-number"><input type="number" name="' . $h($key) . '" min="' . $min . '" max="' . $max . '" required value="' . (int) $cfg[$key] . '"><span>' . $h($unit) . '</span></div></label>';
        }
        echo '</div><label class="pn-self-option"><input type="checkbox" name="suppress_self" value="1"' . $check('suppress_self') . '> Não avisar o técnico sobre ações feitas por ele mesmo.</label>';
        echo '<p class="pn-help">O GLPI precisa estar aberto e autenticado. Em abas ocultas, a consulta fica mais lenta e os alertas flutuantes aguardam o retorno. Eventos com mais de 5 minutos permanecem no histórico, sem gerar uma sequência de popups.</p>';
        echo '<div class="pn-save-row"><p class="pn-help">Desativar a chave principal interrompe eventos reais, mas preserva o histórico.</p><button class="pn-btn pn-btn-primary" type="submit">Salvar configurações</button></div></section></form>';
        echo '<section class="pn-config-card" id="pn-user-card"><div class="pn-card-heading"><div><h2>Regras por usuário</h2><p>Escolha quem precisa receber avisos diferentes do padrão geral.</p></div><span class="pn-tag">Somente administrador</span></div>';
        echo '<label class="pn-field pn-user-search"><span>Buscar por nome, login ou ID</span><input type="search" id="pn-user-search" placeholder="Digite pelo menos 2 caracteres" autocomplete="off"></label>';
        echo '<select id="pn-user-results" class="pn-select" aria-label="Resultados da busca" hidden></select><p class="pn-help" id="pn-user-label">Nenhum usuário selecionado.</p>';
        echo '<div id="pn-user-rules" hidden><div class="pn-user-rule-grid">';
        foreach (Settings::TYPES as $type => $label) {
            echo '<label class="pn-field"><span>' . $h($label) . '</span><select class="pn-select" data-user-rule="' . $h($type) . '"><option value="-1">Herdar regra geral</option><option value="1">Ativar para este usuário</option><option value="0">Desativar para este usuário</option></select></label>';
        }
        echo '</div><div class="pn-save-row"><p class="pn-help">Uma exceção pode substituir o padrão de cada tipo, mas nunca a chave principal. O usuário não pode alterar estas regras.</p><button class="pn-btn pn-btn-primary" type="button" id="pn-user-save">Salvar regras do usuário</button></div></div><div id="pn-admin-message" role="status" aria-live="polite"></div></section>';
        echo '<footer class="pn-admin-footer">Escopo global &middot; Super-Admin ou perfil equivalente na entidade raiz &middot; Desenvolvido por Kawan Costa</footer></main>';
    }
}

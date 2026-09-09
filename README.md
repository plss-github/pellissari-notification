# Pellissari Notification 0.1.2 - GLPI 11

Plugin desenvolvido para GLPI 11, com central de notificações para técnicos, histórico e regras administrativas.

**Autor:** Kawan Costa  
**GitHub:** https://github.com/KawanCostaNs

## Funcionalidades

| Evento | Quem recebe |
|---|---|
| Chamado atribuído a um grupo | Integrantes diretos do grupo com usuário ativo e algum perfil de interface central; o acesso ao chamado e a entidade e validado novamente antes de exibir. |
| Chamado atribuído a uma pessoa | O novo técnico responsável. |
| Status alterado | Técnicos diretamente atribuídos no momento do evento. |
| Comentário do solicitante | Técnicos diretamente atribuídos, quando o autor e um requerente registrado e o acompanhamento e público. |

Alertas flutuantes no canto superior direito. Sininho fixo no canto inferior direito, contador, histórico paginado, filtro por tipo e por não lidas, abertura do chamado, marcação individual ou coletiva como lida. Fechar um popup não o marca como lido.

Configuração global: ativação, regras por evento, quatro cores, intervalo de consulta (5-120 segundos), duração do popup (5-60 segundos), retenção (7-365 dias) e supressão opcional de ações do próprio técnico. Padrões: 10 segundos de consulta, 10 segundos de popup, 90 dias de histórico, quatro eventos ativos, ações próprias incluídas.

Exceções por usuário: herdar, ativar ou desativar cada tipo. Uma exceção substitui o padrão do evento, mas não a chave principal. Regras de recebimento afetam eventos novos; não apagam o histórico. As cores são lidas da configuração atual.

**Teste privado:** cada linha tem "Testar para mim". Usa a cor selecionada mesmo sem salvar, grava apenas para o administrador autenticado e não cria nem altera chamado real. Não existe parâmetro de destinatário para testes. Um teste pode ser feito a cada 3 segundos. Funciona mesmo quando o evento ou a chave geral estiver desligado.

## Requisitos e escopo

GLPI 11.x, PHP 8.2 ou superior e banco MySQL/MariaDB já utilizado pelo GLPI. O pacote não baixa dependências e não precisa de Composer, npm, SMTP, WebSocket ou serviço externo em execução.

O campo de versão do plugin permite GLPI 11.0.x. GLPI 10 e 12 não são suportados por este pacote.

A tela administrativa exige interface central, direito de atualizar configurações (`config`, UPDATE), direito de atualizar perfis (`profile`, UPDATE) e entidade raiz entre as entidades ativas. Isso inclui o Super-Admin padrão e perfis com privilégios equivalentes. Não há dependência do nome nem de um ID fixo de perfil. Um administrador restrito a uma entidade não pode alterar regras globais.

## Instalação

1. Faça backup do banco e dos arquivos do GLPI antes da instalação.
2. Extraia o ZIP. Copie a pasta **pellissarinotification** para a pasta **plugins** da instalação, sem renomear. A estrutura deve ser `SEU_GLPI/plugins/pellissarinotification/setup.php`, e não `plugins/pellissarinotification/pellissarinotification/setup.php`.
3. Conceda ao usuário do PHP/servidor web leitura dos arquivos e acesso aos diretórios. O plugin não exige escrita em seu próprio diretório. Não use permissão 777.
4. Entre no GLPI com Super-Admin na entidade raiz. Na administração de plugins, instale e ative **Pellissari Notification - Central de notificações**.
5. Atualize a página. Abra **Configurar > Pellissari Notification**, ou o botão **Configurar** do próprio sininho. A rota relativa e `/plugins/pellissarinotification/Config`; preserve um eventual prefixo de subdiretório da sua instalação.
6. Use os quatro botões **Testar para mim** para conferir as cores e a exibição dos alertas. Depois ajuste as regras e habilite **Ativar notificações reais** conforme necessário.
7. Confira a ação automática **Purge**, do tipo Pellissari Notification, em **Configurar > Ações automáticas**. Foi registrada em modo externo, com frequência diária; a exclusão física depende do cron do GLPI funcionando. A entrega dos avisos não depende desse cron.

Instalação por arquivo no servidor, caso o seu GLPI esteja em `/var/www/glpi` (ajuste os caminhos):

```bash
sudo unzip /tmp/pellissari-notification-0.1.2.zip -d /var/www/glpi/plugins
```

Não e necessário editar o core nem executar SQL manualmente. Se a rota não aparecer depois de instalar/ativar, limpe o cache pelo procedimento da sua instalação e recarregue a página. Não altere permissões ou a configuração de produção sem diagnóstico.

## Comportamento da entrega

O evento e registrado pelos hooks do GLPI. O navegador consulta periodicamente o banco por endpoints autenticados; não e push instantâneo. Com o GLPI visível, o intervalo padrão e 10 segundos, ajustável de 5 a 120, mais latência e tempo de processamento. Não há garantia de entrega dentro de um prazo fixo.

Abas ocultas consultam a cada pelo menos 30 segundos e não apresentam/consomem os popups até o retorno. O navegador pode limitar ainda mais seus temporizadores. Com todas as abas ou o navegador fechados, não existe notificação de Windows/push. O histórico permanece no banco.

Somente eventos com até 5 minutos geram popups; avisos mais antigos ficam no histórico. Há no máximo três popups simultâneos. A reivindicação de exibição e atômica no servidor para reduzir duplicações entre abas e dispositivos. Se a página fechar imediatamente depois da reivindicação, o popup pode não ser visto; o registro permanece não lido no histórico.

A consulta e feita por aba, não por usuário único. O backend usa índices e limites de leitura, mas não foi submetido a teste de carga. Valide CPU, tempo de resposta e banco antes de reduzir o intervalo. O indicador para em 99+ e pode mostrar um limite inferior com '+' quando a verificação alcança 1.000 registros candidatos. Ele e um indicador de caixa de entrada, não uma métrica analítica.

## Regras importantes

- Um usuário com dois eventos distintos (atribuição ao grupo e diretamente a pessoa) pode receber dois registros se ambas as regras estiverem ativas. Isso e intencional, não uma duplicação do mesmo evento.
- Grupos-pai/filho não são expandidos. São considerados os membros diretos do grupo atribuído no momento do evento. Entrar no grupo depois não distribui avisos retroativos.
- Status e comentários avisam os técnicos **diretamente** atribuídos, não todos os membros de todos os grupos do chamado.
- "Solicitante" e o usuário com vínculo de requerente (`CommonITILActor::REQUESTER`) no chamado. Não e qualquer autor de comentário nem, necessariamente, o operador que abriu o chamado em nome de outra pessoa.
- Comentários anônimos/sem usuário resolvido, comentários privados, tarefas, soluções e comentários de técnicos não geram o evento de comentário do solicitante. Acompanhamentos via coletor só entram se o GLPI resolver o autor como requerente.
- Alterações realizadas via interface, API ou regras podem acionar os hooks quando seguem os objetos nativos. Atualização direta via SQL não dispara esses hooks.
- Não são criadas notificações retroativas para eventos anteriores a instalação.
- O plugin não altera nem desliga os alertas nativos do GLPI. Caso outros canais estejam ativos, o técnico pode receber avisos por ambos.

## Segurança e privacidade

A lista, os popups, a contagem e as alterações de leitura são limitados ao usuário da sessão. Cada chamado e validado pelos direitos e entidades ativas do GLPI, inclusive hooks de restrição de acesso. Comentários privados ou excluídos deixam de ser exibidos no histórico. Ver `docs/SEGURANCA.md`.

Nenhum título ou corpo de comentário e copiado para a tabela de notificações; são armazenados identificadores e metadados mínimos. O título apresentado e o atual do chamado, não uma fotografia imutável do momento do evento. O popup de comentário informa que houve acompanhamento público, sem reproduzir seu conteúdo.

## Validação executada e pendente

Executado: verificação de sintaxe PHP e JavaScript; 47 testes PHP isolados de regras e proteções, com classes/banco simulados; ensaios de interface em navegador sobre demonstração local simulada.

Não executado: instalação no GLPI real, criação das tabelas em MySQL/MariaDB real, roteamento/autenticação/CSRF do kernel real, integração com outros plugins, tema do seu ambiente, multiaba concorrente em servidor real e carga. Os testes simulados não substituem esses testes.

Para repetir os testes isolados, em uma cópia de desenvolvimento:

```bash
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n 1 php -l
node --check public/js/pellissarinotification.js
```

`tests/bootstrap.php` contém dublos exclusivos para testes. Não o inclua no bootstrap do GLPI.

## Desativação, desinstalação e diagnóstico

**Desativar** preserva as tabelas, configurações e histórico. **Desinstalar apaga as três tabelas do Pellissari Notification**, incluindo suas preferências e histórico, sem apagar chamados do GLPI. Faça backup antes de desinstalar.

Tabelas: `glpi_plugin_pellissarinotification_configs`, `glpi_plugin_pellissarinotification_userrules` e `glpi_plugin_pellissarinotification_notifications`.

Erros capturados pelos hooks vão ao log `pellissarinotification` do GLPI; verifique também os logs PHP/SQL da instância. No navegador, verifique as respostas de `/plugins/pellissarinotification/Feed`, `/History`, `/Token` e `/Action`, sem compartilhar cookies ou tokens. API e páginas administrativas enviam `Cache-Control: no-store, private`; um proxy/CDN não deve sobrescrever isso ou armazenar respostas autenticadas.

## Referências técnicas consultadas (09/09/2026)

- Hooks: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/hooks.html
- Controllers e compatibilidade de métodos antes do GLPI 11.0.7: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/controllers.html
- Estrutura e instalação: https://glpi-developer-documentation.readthedocs.io/en/latest/plugins/requirements.html
- Código-base de referência: https://github.com/glpi-project/glpi/tree/11.0.8

Estas referências foram usadas para consultar interfaces. O pacote não inclui o código-fonte do GLPI.

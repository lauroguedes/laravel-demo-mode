<?php

declare(strict_types=1);

return [

    'banner' => [
        'with_countdown' => 'Esta é uma demonstração. Tudo o que você alterar aqui será apagado em :time.',
        'without_countdown' => 'Esta é uma demonstração. Tudo o que você alterar aqui é apagado periodicamente.',
        'dismiss' => 'Dispensar',

        'units' => ['hour' => 'h', 'minute' => 'min', 'second' => 's'],
    ],

    /*
     | A barra flutuante. A frase é a mesma do banner; estes são os rótulos
     | dos controles que só ela tem.
     */
    'bar' => [
        /*
         | Shorter than the banner's sentence, because the bar is a pill and a
         | pill full of prose is a pill the width of the screen.
         */
        'with_countdown' => 'Reinicia em :time',
        'without_countdown' => 'Reinicia periodicamente',

        'reset' => 'Reconstruir a demonstração',
        'confirm' => 'Reconstruir a demonstração?',
        'confirm_yes' => 'Reconstruir',
        'cancel' => 'Cancelar',
        'working' => 'Reconstruindo a demonstração…',
        'failed' => 'Não funcionou. Tente de novo em instantes.',
    ],

    'credentials' => [
        'heading' => 'Entre com',
        'email' => 'E-mail',
        'password' => 'Senha',
        'copy' => 'Copiar',
        'copied' => 'Copiado',
    ],

    'reset' => [
        'queued' => 'A demonstração está sendo reconstruída. Aguarde um instante e recarregue.',
        'done' => 'A demonstração foi reconstruída.',
    ],

    'errors' => [
        'read_only' => 'Esta demonstração é somente leitura.',
        'write_prohibited' => 'Isso não pode ser alterado na demonstração.',
        'privileged_account' => 'O acesso com esta conta está desabilitado na demonstração.',
        'cooldown' => 'Esta demonstração foi reiniciada há pouco. Tente de novo em :time.',
        'in_progress' => 'Esta demonstração já está sendo reconstruída. Aguarde um instante e recarregue.',
        'unavailable' => 'Esta demonstração não pode ser reconstruída agora.',
        'not_found' => 'Não encontrado.',
    ],

];

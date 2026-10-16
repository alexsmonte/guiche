<?php

declare(strict_types=1);

use Asmpkg\Guiche\Guiche;
use GuicheExample\Chatwoot\ChatwootWebhookAdapter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/src/ChatwootWebhookAdapter.php';

/*
 * IMPORTANTE
 * ==========
 *
 * Este arquivo é um exemplo de integração e NÃO autentica o webhook.
 *
 * Antes de chamar esta função, o aplicativo hospedeiro deve ter
 * estabelecido que o request realmente é confiável segundo a política
 * de segurança do seu ambiente.
 *
 * Nunca transforme diretamente qualquer JSON recebido da Internet em
 * trustedPayload apenas porque ele tem formato parecido com Chatwoot.
 */

/**
 * @param array<mixed> $trustedPayload
 * @return array<mixed>
 */
function handleTrustedChatwootWebhook(
    Guiche $guiche,
    array $trustedPayload
): array {
    $adapter = new ChatwootWebhookAdapter();

    return $adapter->handleTrustedIncoming(
        $guiche,
        $trustedPayload
    );
}

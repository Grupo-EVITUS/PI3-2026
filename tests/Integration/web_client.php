<?php
declare(strict_types=1);

// Navegador de teste compartilhado pelos percursos HTTP (cookies, CSRF e Post/Redirect/Get).

/** Navegador mínimo com cookies. @return array{0: int, 1: string, 2: array<string, string>} */
function browse(array &$jar, string $url, ?array $form = null): array
{
    $headers = ['Accept: text/html,application/json'];
    if ($jar !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar));
    }
    $options = ['method' => $form === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15];
    if ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($form);
    }
    $options['header'] = implode("\r\n", $headers);
    $stream = fopen($url, 'rb', false, stream_context_create(['http' => $options]));
    $body = stream_get_contents($stream);
    $raw = stream_get_meta_data($stream)['wrapper_data'];
    fclose($stream);
    preg_match('#^HTTP/\S+\s+(\d{3})#', $raw[0], $status);
    $received = [];
    foreach (array_slice($raw, 1) as $line) {
        [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
        $received[strtolower($name)] = $value;
        if (strtolower($name) === 'set-cookie' && preg_match('/^([^=;]+)=([^;]*)/', $value, $cookie)) {
            $jar[$cookie[1]] = $cookie[2];
        }
    }
    if (in_array((int)$status[1], [302, 303], true) && isset($received['location'])) {
        // Post/Redirect/Get, como um navegador.
        $location = $received['location'];
        return browse($jar, str_starts_with($location, '/') ? preg_replace('#^(https?://[^/]+).*$#', '$1', $url) . $location : $location);
    }
    return [(int)$status[1], (string)$body, $received];
}

function csrfFrom(string $page): string
{
    preg_match('/name="csrf_token" value="([^"]+)"/', $page, $match);
    return $match[1] ?? throw new RuntimeException('web_client: CSRF ausente.');
}

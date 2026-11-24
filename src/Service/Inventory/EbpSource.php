<?php

namespace App\Service\Inventory;

use DomainException;

final class EbpSource
{
    public const int MAX_BYTES = 20 * 1024 * 1024;

    public function httpsUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (strlen($url) > 2000 || !filter_var($url, FILTER_VALIDATE_URL) || !$parts
            || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new DomainException('Indiquez une URL HTTPS complète, sans identifiants intégrés.');
        }

        return $url;
    }

    /** @return resource */
    public function download(string $url)
    {
        if (!function_exists('curl_init')) {
            throw new DomainException('Activez l’extension cURL de PHP pour joindre la source EBP.');
        }
        $url = $this->httpsUrl($url);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $addresses = $this->publicAddresses(trim($host, '[]'));
        $file = fopen('php://temp', 'w+b');
        $bytes = 0;
        $curl = curl_init($url);
        if ($file === false || $curl === false) {
            throw new \RuntimeException('Le téléchargement ne peut pas être initialisé.');
        }
        $pinned = array_map(static fn (string $ip) => str_contains($ip, ':') ? '['.$ip.']' : $ip, $addresses);
        try {
            curl_setopt_array($curl, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $pinned)],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 18,
                CURLOPT_USERAGENT => 'Hopic Inventaire EBP',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($file, &$bytes): int {
                    $bytes += strlen($data);

                    return $bytes <= self::MAX_BYTES ? (int) fwrite($file, $data) : 0;
                },
            ]);
            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($bytes > self::MAX_BYTES) {
                throw new DomainException('Le fichier dépasse la limite de 20 Mo.');
            }
            if ($status >= 300 && $status < 400) {
                throw new DomainException('La source redirige vers une autre URL. Renseignez l’adresse HTTPS finale.');
            }
            if ($status >= 500 || in_array($status, [408, 425, 429], true)) {
                throw new EbpUnavailable('Le serveur EBP est temporairement indisponible.');
            }
            if ($status >= 400) {
                throw new DomainException('Le serveur EBP a répondu avec le statut HTTP '.$status.'. Vérifiez le lien.');
            }
            if ($result === false || $status === 0) {
                if (in_array(curl_errno($curl), [CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT_BADFILE], true)) {
                    throw new DomainException('Le certificat HTTPS de la source ne peut pas être vérifié.');
                }
                throw new EbpUnavailable('Connexion à la source EBP interrompue ou indisponible.');
            }
            if ($status !== 200) {
                throw new DomainException('Aucun fichier CSV complet n’a été renvoyé.');
            }
            rewind($file);

            return $file;
        } catch (\Throwable $error) {
            fclose($file);
            throw $error;
        } finally {
            curl_close($curl);
        }
    }

    private function publicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $addresses = array_filter(array_map(static fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records));
        }
        if (!$addresses) {
            throw new EbpUnavailable('Le nom du serveur EBP ne répond pas pour le moment.');
        }
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || str_starts_with(strtolower($ip), '::ffff:')) {
                throw new DomainException('La source CSV doit être accessible sur une adresse Internet publique.');
            }
        }

        return array_values(array_unique($addresses));
    }
}

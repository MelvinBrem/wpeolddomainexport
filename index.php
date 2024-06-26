<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use League\Csv\Writer;

require_once 'vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

if (file_exists('output/sites.php')) {
    require_once 'output/sites.php';
}

if (!empty($_GET['action'])) {
    switch ($_GET['action']) {
        case 'getinstalls':
            get_installs();
            break;
        case 'getdomainsdns':
            if (file_exists('output/sites.php')) {
                require_once 'output/sites.php';
            }
            if (!empty($installs)) {
                get_domains_dns($installs);
            }
            break;
        case 'clean':
            if (file_exists('output/sites.php')) {
                require_once 'output/sites.php';
            }
            if (!empty($installs)) {
                clean_installs($installs);
            }
            break;
        case 'tocsv':
            if (file_exists('output/sites_clean.php')) {
                require_once 'output/sites_clean.php';
            }
            if (!empty($installs)) {
                to_csv($installs);
            }
            break;
    }
} else {
    $installs = get_installs();
    get_domains_dns($installs);
    clean_installs($installs);
    to_csv($installs);
}

// Git installs
function get_installs(): array
{
    $ch = curl_init();
    $installs = [];

    curl_setopt($ch, CURLOPT_URL, 'https://api.wpengineapi.com/v1/installs?limit=1000');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

    $headers = array();
    $cred_string = $_ENV['WPE_API_UN'] . ":" . $_ENV['WPE_API_PW'];
    $headers[] = "Authorization: Basic " . base64_encode($cred_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }

    $data = json_decode($result, true);
    foreach ($data['results'] as $result) {
        if ($result['environment'] !== 'production') continue;

        $installs[$result['name']] = [
            'id' => $result['id']
        ];
    }

    // Save output to minimize API calls
    $installs_str = var_export($installs, true);
    $var = "<?php" . PHP_EOL . "\$installs = $installs_str;" . PHP_EOL . "?>";
    fopen('output/sites.php', 'w');
    file_put_contents('output/sites.php', $var);

    return $installs;
}

// Git DNS records
function get_domains_dns(array $installs): void
{
    if (empty($installs)) throw new Exception("No installs");

    $ch = curl_init();

    foreach ($installs as &$install) {
        curl_setopt($ch, CURLOPT_URL, 'https://api.wpengineapi.com/v1/installs/' . $install['id'] . '/domains');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $headers = array();
        $cred_string = '16f91498-4edb-434c-897d-93c2110d8e8d' . ":" . 'g4AfA4W5CjVzIrv1TAuG3lCYmz0dY44s';
        $headers[] = "Authorization: Basic " . base64_encode($cred_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }

        $domainsResults = json_decode($result, true);
        if (empty($domainsResults['results'])) continue;

        foreach ($domainsResults['results'] as $domain) {
            if (
                str_contains($domain['name'], 'wpengine') ||
                in_array($domain['name'], ['wpengine.com', 'wpengine.net']) ||
                !empty($installs['domains']) && in_array($domain['name'],  $install['domains'])
            ) {
                continue;
            };

            $domainRecords = dns_get_record($domain['name'], DNS_A);

            $ipsToIgnore = ['141.193.213.20', '141.193.213.21'];

            if (!empty($domainRecords)) {
                foreach ($domainRecords as $domainRecord) {
                    if (in_array($domainRecord['ip'], $ipsToIgnore)) {
                        continue;
                    }

                    $install['domains'][$domain['name']] = $domainRecord['ip'];
                }
            }
        }

        $installs_str = var_export($installs, true);
        $var = "<?php" . PHP_EOL . "\$installs = $installs_str;" . PHP_EOL . "?>";
        fopen('output/sites.php', 'w');
        file_put_contents('output/sites.php', $var);
    }
}

// Clean up empty/ installs with no DNS issues
function clean_installs(array $installs): void
{
    if (empty($installs)) throw new Exception("No installs");

    if (empty($_GET['justclean']) || empty($_GET['justclean']) && $_GET['justclean'] !== 'true') {
        foreach ($installs as $key => $install) {
            if (empty($install['domains'])) {
                unset($installs[$key]);
            }
        }
    }

    $installs_str = var_export($installs, true);
    $var = "<?php" . PHP_EOL . "\$installs = $installs_str;" . PHP_EOL . "?>";
    fopen('output/sites_clean.php', 'w');
    file_put_contents('output/sites_clean.php', $var);
}

// Array to CSV, domain per row
function to_csv(array $installs): void
{
    if (empty($installs)) throw new Exception("No installs");

    $header = ['install', 'domain', 'current_ip'];
    $records = [];

    $csv = Writer::createFromString();
    $csv->insertOne($header);

    foreach ($installs as $installname => $installData) {
        foreach ($installData['domains'] as $domain => $ip) {
            $records[] = [$installname, $domain, $ip];
        }
    }

    $csv->insertAll($records);

    fopen('output/sites_clean.csv', 'w');
    file_put_contents('output/sites_clean.csv', $csv->toString());
}

<?php
/**
 * UniGo - demo vehicle image seeder.
 *
 * The demo companies ship without any vehicle photos, so every place that is
 * wired to show them (passenger trip search, the trip gallery, and the
 * operator/admin fleet photo box) renders empty. This seeder downloads real,
 * freely-licensed vehicle photographs from Wikimedia Commons (CC BY-SA; see
 * photo_sources()) for each test vehicle and records them in vehicle_images,
 * using the same relative-path convention as a real upload
 * (uploads/vehicles/<vehicleId>/exterior.jpg under public/). Attributions are
 * kept on each row's caption. If a download fails (e.g. offline), the seeder
 * falls back to a flat SVG illustration so the demo still renders.
 *
 * Images live in a separate table (vehicle_images) already read by:
 *   - VehicleModel::coverSql()  -> passenger /trips/search cards
 *   - TripModel                 -> passenger /trips/{id} gallery
 *   - VehicleModel::photosFor() -> staff /operator/vehicles + /admin/vehicles
 *
 * Every test vehicle uses the reserved @unigo.test domain, so this only runs
 * when UNIGO_DEMO_MODE=1 (never in production) and against a disposable
 * database.
 *
 * Usage:
 *   php database/seed-vehicle-images.php          # add images (skips existing)
 *   php database/seed-vehicle-images.php --fresh  # replace this seeder's images
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("The vehicle image seeder may only be run from the command line.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/src/bootstrap.php';

if (!is_demo_mode()) {
    fwrite(STDERR, "Demo seeding is disabled. Use UNIGO_DEMO_MODE=1 only on a disposable test database.\n");
    exit(1);
}

use App\Core\Database;

$fresh = in_array('--fresh', array_slice($argv, 1), true);

function say(string $step): void
{
    echo '  - ' . $step . PHP_EOL;
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Accent + sky colours per vehicle type. */
function palette(string $type): array
{
    return match ($type) {
        'taxi'  => ['accent' => '#EAB308', 'sky1' => '#FEF9C3', 'sky2' => '#FEFCE8'],
        'truck' => ['accent' => '#1D4ED8', 'sky1' => '#DBEAFE', 'sky2' => '#EFF6FF'],
        default => ['accent' => '#0E7490', 'sky1' => '#DBEAFE', 'sky2' => '#EFF6FF'],
    };
}

/** Shared backdrop (sky, sun, clouds, ground, shadow) + label overlay. */
function scene(string $accent, string $sky1, string $sky2, string $body, string $company, string $caption, string $reg, string $aria): string
{
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" width="800" height="500" role="img" aria-label="{$aria}">
  <defs>
    <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="{$sky1}"/><stop offset="1" stop-color="{$sky2}"/>
    </linearGradient>
    <linearGradient id="glass" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#E0F2FE"/><stop offset="1" stop-color="#93C5FD"/>
    </linearGradient>
  </defs>
  <rect width="800" height="500" fill="url(#sky)"/>
  <circle cx="672" cy="96" r="46" fill="#FDE68A"/>
  <g fill="#FFFFFF" opacity="0.85">
    <ellipse cx="180" cy="98" rx="70" ry="26"/>
    <ellipse cx="242" cy="88" rx="52" ry="22"/>
    <ellipse cx="556" cy="120" rx="60" ry="22"/>
  </g>
  <rect y="384" width="800" height="116" fill="#E7E9EE"/>
  <rect y="384" width="800" height="5" fill="#CFD4DC"/>
  <ellipse cx="400" cy="448" rx="290" ry="20" fill="#0F172A" opacity="0.08"/>
{$body}
  <g font-family="Segoe UI, Roboto, Helvetica, Arial, sans-serif">
    <rect x="24" y="22" width="{W1}" height="40" rx="20" fill="#0F172A" opacity="0.72"/>
    <text x="44" y="48" fill="#FFFFFF" font-size="18" font-weight="600">{$company}</text>
    <rect x="24" y="432" width="{W2}" height="36" rx="12" fill="{$accent}"/>
    <text x="44" y="456" fill="#FFFFFF" font-size="16" font-weight="600">{$caption}</text>
    <rect x="632" y="424" width="144" height="44" rx="10" fill="#F8FAFC" stroke="#0F172A" stroke-width="3"/>
    <text x="704" y="453" text-anchor="middle" fill="#0F172A" font-size="20" font-weight="700">{$reg}</text>
  </g>
</svg>

SVG;
}

/** Widths for the two chips, estimated from the text so the pills hug the label. */
function scene_sized(string $accent, string $sky1, string $sky2, string $body, string $company, string $caption, string $reg, string $aria): string
{
    $svg = scene($accent, $sky1, $sky2, $body, $company, $caption, $reg, $aria);
    $svg = str_replace('{W1}', (string) (28 + (int) round(mb_strlen($company) * 10.4)), $svg);
    $svg = str_replace('{W2}', (string) (28 + (int) round(mb_strlen($caption) * 9.2)), $svg);
    return $svg;
}

function exterior(string $type, string $accent): string
{
    if ($type === 'taxi') {
        return <<<SVG
  <g stroke-linejoin="round">
    <path d="M170 344 v-118 q0 -20 20 -30 l150 -50 q22 -8 46 -8 h150 q26 0 34 20 l34 78 v108 q0 12 -12 12 H182 q-12 0 -12 -12 z" fill="#FDE047" stroke="#EAB308" stroke-width="3"/>
    <path d="M350 152 l18 -22 q8 -10 24 -10 h92 q16 0 24 12 l18 20 z" fill="#FDE047" stroke="#EAB308" stroke-width="3"/>
    <rect x="368" y="100" width="98" height="32" rx="8" fill="#111827"/>
    <text x="417" y="124" text-anchor="middle" fill="#FDE047" font-size="20" font-weight="700" font-family="Segoe UI, Arial, sans-serif">TAXI</text>
    <rect x="196" y="204" width="78" height="70" rx="10" fill="url(#glass)"/>
    <rect x="288" y="198" width="92" height="76" rx="10" fill="url(#glass)"/>
    <rect x="394" y="198" width="92" height="76" rx="10" fill="url(#glass)"/>
    <rect x="500" y="204" width="74" height="70" rx="10" fill="url(#glass)"/>
    <rect x="170" y="300" width="470" height="18" fill="#111827" opacity="0.85"/>
    <rect x="608" y="312" width="18" height="24" rx="4" fill="#FEF9C3" stroke="#EAB308"/>
    <circle cx="272" cy="374" r="42" fill="#1F2937"/><circle cx="272" cy="374" r="16" fill="#CBD5E1"/>
    <circle cx="560" cy="374" r="42" fill="#1F2937"/><circle cx="560" cy="374" r="16" fill="#CBD5E1"/>
  </g>
SVG;
    }

    if ($type === 'truck') {
        return <<<SVG
  <g stroke-linejoin="round">
    <rect x="140" y="146" width="336" height="234" rx="12" fill="#FFFFFF" stroke="#CBD5E1" stroke-width="3"/>
    <rect x="140" y="146" width="336" height="234" rx="12" fill="{$accent}" opacity="0.12"/>
    <rect x="140" y="146" width="336" height="20" rx="8" fill="{$accent}"/>
    <rect x="176" y="200" width="120" height="120" rx="10" fill="#FFFFFF" stroke="#CBD5E1" stroke-width="2"/>
    <path d="M476 208 h130 q22 0 32 20 l20 42 q6 14 6 30 v62 q0 12 -12 12 H476 z" fill="{$accent}"/>
    <rect x="498" y="222" width="66" height="54" rx="8" fill="url(#glass)"/>
    <rect x="512" y="300" width="150" height="26" rx="6" fill="#FFFFFF" opacity="0.25"/>
    <rect x="632" y="302" width="18" height="26" rx="4" fill="#FEF9C3"/>
    <circle cx="230" cy="392" r="44" fill="#1F2937"/><circle cx="230" cy="392" r="17" fill="#CBD5E1"/>
    <circle cx="560" cy="392" r="44" fill="#1F2937"/><circle cx="560" cy="392" r="17" fill="#CBD5E1"/>
    <circle cx="636" cy="392" r="44" fill="#1F2937"/><circle cx="636" cy="392" r="17" fill="#CBD5E1"/>
  </g>
SVG;
    }

    // bus (default)
    return <<<SVG
  <g stroke-linejoin="round">
    <rect x="120" y="146" width="560" height="236" rx="28" fill="#FFFFFF" stroke="#CBD5E1" stroke-width="3"/>
    <rect x="120" y="146" width="560" height="22" rx="11" fill="{$accent}"/>
    <rect x="120" y="330" width="560" height="26" fill="{$accent}" opacity="0.9"/>
    <g fill="url(#glass)" stroke="#93C5FD" stroke-width="2">
      <rect x="150" y="184" width="86" height="92" rx="10"/>
      <rect x="250" y="184" width="86" height="92" rx="10"/>
      <rect x="350" y="184" width="86" height="92" rx="10"/>
      <rect x="450" y="184" width="86" height="92" rx="10"/>
      <rect x="550" y="184" width="86" height="92" rx="10"/>
    </g>
    <rect x="596" y="192" width="46" height="142" rx="8" fill="url(#glass)" stroke="#93C5FD" stroke-width="2"/>
    <rect x="636" y="298" width="16" height="24" rx="4" fill="#FEF08A" stroke="#FDE68A"/>
    <circle cx="250" cy="392" r="44" fill="#1F2937"/><circle cx="250" cy="392" r="17" fill="#CBD5E1"/>
    <circle cx="556" cy="392" r="44" fill="#1F2937"/><circle cx="556" cy="392" r="17" fill="#CBD5E1"/>
  </g>
SVG;
}

function interior(string $accent, int $seats): string
{
    $rows = '';
    $y = 170;
    for ($i = 0; $i < 3; $i++) {
        $shade = $i === 0 ? $accent : $accent;
        $rows .= '    <g>' . "\n"
            . '      <rect x="118" y="' . $y . '" width="176" height="34" rx="12" fill="' . $shade . '" opacity="0.35"/>' . "\n"
            . '      <rect x="118" y="' . ($y + 34) . '" width="176" height="58" rx="14" fill="' . $shade . '"/>' . "\n"
            . '      <rect x="506" y="' . $y . '" width="176" height="34" rx="12" fill="' . $shade . '" opacity="0.35"/>' . "\n"
            . '      <rect x="506" y="' . ($y + 34) . '" width="176" height="58" rx="14" fill="' . $shade . '"/>' . "\n"
            . '    </g>' . "\n";
        $y += 96;
    }
    return <<<SVG
  <g>
    <rect x="0" y="0" width="800" height="112" fill="#CFE8FB"/>
    <rect x="0" y="112" width="800" height="10" fill="#94A3B8"/>
    <rect x="300" y="122" width="200" height="262" fill="#DDE3EA"/>
    <rect x="396" y="122" width="8" height="262" fill="#CAD2DB"/>
{$rows}    <text x="400" y="150" text-anchor="middle" fill="#475569" font-size="18" font-weight="600" font-family="Segoe UI, Arial, sans-serif">{$seats} seat cabin</text>
  </g>
SVG;
}

/**
 * Real, freely-licensed photos from Wikimedia Commons (CC BY-SA), one exterior
 * and one interior per vehicle type. Downloaded at seed time; the caption on
 * each stored row carries the attribution.
 */
function photo_sources(): array
{
    return [
        'bus' => [
            'exterior' => [
                'url'    => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/1/1a/ISUZU_GALA%2C_Kirakira-11%2C_Coach_Number_206%2C_Front_Perspective_View.jpg/1280px-ISUZU_GALA%2C_Kirakira-11%2C_Coach_Number_206%2C_Front_Perspective_View.jpg',
                'credit' => 'CC BY-SA 3.0, Mj-bird',
            ],
            'interior' => [
                'url'    => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/6/62/From_the_Q41_td_01_-_Bus_seats.jpg/1280px-From_the_Q41_td_01_-_Bus_seats.jpg',
                'credit' => 'CC BY-SA 4.0, Tdorante10',
            ],
        ],
        'taxi' => [
            'exterior' => [
                'url'    => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/1/1f/1989_Toyota_HiAce_9-seater_minibus_%28LH51%29%2C_front_right.jpg/1280px-1989_Toyota_HiAce_9-seater_minibus_%28LH51%29%2C_front_right.jpg',
                'credit' => 'CC BY-SA 3.0, Mr.choppers',
            ],
            'interior' => [
                'url'    => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/d/d5/Toyota_Hiace_KDH202L_3.0_Commuter_-_interior_view.jpg/1280px-Toyota_Hiace_KDH202L_3.0_Commuter_-_interior_view.jpg',
                'credit' => 'CC BY-SA 4.0, Ethan Llamas',
            ],
        ],
        'truck' => [
            'exterior' => [
                'url'    => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/0/05/Isuzu_Forward_FVR_Wing_Van_Truck_2020.jpg/1280px-Isuzu_Forward_FVR_Wing_Van_Truck_2020.jpg',
                'credit' => 'CC BY-SA 4.0, Captainmorlypogi1959',
            ],
            'interior' => [
                'url'    => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/f/f5/HK_Sheung_Wan_Upper_Station_Street_logistics_truck_Isuzu_Lorry_car_interior_July_2016_DSC.jpg/1280px-HK_Sheung_Wan_Upper_Station_Street_logistics_truck_Isuzu_Lorry_car_interior_July_2016_DSC.jpg',
                'credit' => 'CC BY-SA 4.0, Therae loarryo',
            ],
        ],
    ];
}

/** Fetch a remote JPEG (Wikimedia needs a User-Agent). False on any failure. */
function download_photo(string $url, string $dest): bool
{
    if ($url === '') {
        return false;
    }
    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 30,
            'follow_location' => 1,
            'header'          => "User-Agent: UniGo-demo-seeder/1.0 (+https://unigo.test; demo)\r\n",
        ],
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false || strlen($data) < 4096) {
        return false;
    }
    if (substr($data, 0, 3) !== "\xFF\xD8\xFF") {
        return false;
    }
    return file_put_contents($dest, $data) !== false;
}

$db = Database::instance();

$vehicles = $db->select(
    'SELECT v.id, v.registration_number, v.vehicle_type, v.make, v.model, v.capacity, o.company_name
     FROM vehicles v
     JOIN operators o ON o.id = v.operator_id
     WHERE v.registration_number IN (?, ?, ?)
     ORDER BY v.id',
    ['TEST-BUS-01', 'TEST-TAXI-01', 'TEST-TRK-01']
);

if (!$vehicles) {
    fwrite(STDERR, "No test vehicles found. Run database/seed-test-companies.php first.\n");
    exit(1);
}

$baseDir = PUBLIC_PATH . '/uploads/vehicles';
if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
    fwrite(STDERR, "Could not create {$baseDir}\n");
    exit(1);
}

$added = 0;
foreach ($vehicles as $vehicle) {
    $id     = (int) $vehicle['id'];
    $type   = (string) $vehicle['vehicle_type'];
    $reg    = (string) $vehicle['registration_number'];
    $company = (string) $vehicle['company_name'];
    $model  = trim($vehicle['make'] . ' ' . $vehicle['model']);
    $pal    = palette($type);

    if ($fresh) {
        foreach ($db->select('SELECT id, image_path FROM vehicle_images WHERE vehicle_id = ?', [$id]) as $old) {
            $file = PUBLIC_PATH . '/' . ltrim((string) $old['image_path'], '/');
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $db->delete('vehicle_images', 'vehicle_id = ?', [$id]);
    }

    $dir = $baseDir . '/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Could not create {$dir}\n");
        exit(1);
    }

    $sources = photo_sources()[$type] ?? photo_sources()['bus'];

    $specs = [
        [
            'photo'    => 'exterior.jpg',
            'svg'      => 'exterior.svg',
            'caption'  => 'Exterior · ' . $model,
            'url'      => $sources['exterior']['url'],
            'credit'   => $sources['exterior']['credit'],
            'svg_data' => scene_sized(
                $pal['accent'], $pal['sky1'], $pal['sky2'],
                exterior($type, $pal['accent']),
                $company, 'Exterior · ' . $model, $reg,
                $reg . ' exterior, ' . $model
            ),
        ],
        [
            'photo'    => 'interior.jpg',
            'svg'      => 'interior.svg',
            'caption'  => 'Interior cabin',
            'url'      => $sources['interior']['url'],
            'credit'   => $sources['interior']['credit'],
            'svg_data' => scene_sized(
                $pal['accent'], '#E2E8F0', '#F8FAFC',
                interior($pal['accent'], (int) $vehicle['capacity']),
                $company, 'Interior cabin', $reg,
                $reg . ' interior'
            ),
        ],
    ];

    foreach ($specs as $index => $spec) {
        $caption  = $spec['caption'];
        $relative = 'uploads/vehicles/' . $id . '/' . $spec['photo'];

        if (download_photo((string) $spec['url'], $dir . '/' . $spec['photo'])) {
            $caption .= ' · ' . $spec['credit'];
        } else {
            $relative = 'uploads/vehicles/' . $id . '/' . $spec['svg'];
            file_put_contents($dir . '/' . $spec['svg'], $spec['svg_data']);
            say($reg . ': photo unavailable, drew ' . $spec['svg']);
        }

        $exists = $db->exists('SELECT 1 FROM vehicle_images WHERE vehicle_id = ? AND image_path = ?', [$id, $relative]);
        if ($exists) {
            continue;
        }
        $db->insert('vehicle_images', [
            'vehicle_id' => $id,
            'image_path' => $relative,
            'caption'    => mb_substr($caption, 0, 120),
            'sort_order' => $index,
        ]);
        $added++;
    }
    say($reg . ' (' . $type . '): exterior + interior');
}

say('images added: ' . $added);
say('Done. View them at /trips/search, /trips/{id} and /operator/vehicles.');

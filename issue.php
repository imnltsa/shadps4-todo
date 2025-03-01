<?php

$repo = "shadps4-emu/shadps4-game-compatibility";
$base_url = "https://api.github.com/repos/$repo/issues?per_page=100&page=";
$headers = [
    "User-Agent: PHP-Script",
    "Accept: application/vnd.github.v3+json"
];

echo "Fetching issues from GitHub...\n";
$all_issues = [];
$page = 1;

do {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base_url . $page);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        die("cURL error: " . curl_error($ch) . "\n");
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        die("Failed to fetch issues! HTTP code: $http_code\n");
    }

    $issues = json_decode($response, true);
    if (!is_array($issues)) {
        die("JSON decoding error: " . json_last_error_msg() . "\nResponse:\n$response\n");
    }

    $all_issues = array_merge($all_issues, $issues);
    $page++;

    echo "Fetched page $page, total issues so far: " . count($all_issues) . "\n";

} while (count($issues) === 100);

// --- Group issues by CUSA ID ---
$cusa_issues = [];
$cusa_id_map = [];
$unique_id = 1;

foreach ($all_issues as $issue) {
    if (preg_match('/CUSA\d{5}/', $issue["title"], $matches)) {
        $cusa_id = $matches[0];

        if (!isset($cusa_id_map[$cusa_id])) {
            $cusa_id_map[$cusa_id] = $unique_id++;
        }
        $unique_cusa_id = $cusa_id_map[$cusa_id];

        $title_parts = explode(" - ", $issue["title"]);
        $game_name = $title_parts[1] ?? "Unknown Game";

        if (!isset($cusa_issues[$unique_cusa_id])) {
            $cusa_issues[$unique_cusa_id] = [
                "macOS" => false, "windows" => false, "linux" => false,
                "issue" => null, "game_name" => $game_name, "cusa_id" => $cusa_id
            ];
        }

        $labels = array_column($issue["labels"], "name");

        if (in_array("os-macos", $labels)) {
            $cusa_issues[$unique_cusa_id]["macOS"] = true;
            $cusa_issues[$unique_cusa_id]["issue"] = $issue["html_url"];
        }
        if (in_array("os-linux", $labels)) {
            $cusa_issues[$unique_cusa_id]["linux"] = true;
            $cusa_issues[$unique_cusa_id]["issue"] = $issue["html_url"];
        }
        if (in_array("os-windows", $labels)) {
            $cusa_issues[$unique_cusa_id]["windows"] = true;
            $cusa_issues[$unique_cusa_id]["issue"] = $issue["html_url"];
        }
    }
}

uasort($cusa_issues, fn($a, $b) => strcmp($a["game_name"], $b["game_name"]));

$todo_windows = array_filter($cusa_issues, fn($d) => ($d["macOS"] || $d["linux"]) && !$d["windows"]);
$todo_linux = array_filter($cusa_issues, fn($d) => ($d["windows"] || $d["macOS"]) && !$d["linux"]);
$todo_macos = array_filter($cusa_issues, fn($d) => ($d["windows"] || $d["linux"]) && !$d["macOS"]);

function generateHtml($title, $data) {
    $html = "<html><head><title>$title</title>    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: white;
            color: black;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background-color: #121212;
                color: white;
            }
            a {
                color: #bb86fc;
            }
        }

        h2 {
            text-align: center;
        }
        ul {
            list-style-type: none;
            padding: 0;
        }
        li {
            padding: 8px;
        }
    </style></head><body><h2>$title</h2><p>Here's a list of games that don't yet have an issue for the OS you selected.<br>Clicking a game will bring you to a report for the OS that DOES have a report, but not one for the OS you selected.<br><br><a href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues/new?template=game_compatibility.yml\">Create blank issue</a></p><hr><ul>";
    foreach ($data as $issue) {
        $html .= "<li><a href='https://github.com/shadps4-emu/shadps4-game-compatibility/issues/new?template=game_compatibility.yml&title={$issue['cusa_id']}%20-%20{$issue['game_name']}&game-name={$issue['game_name']}&game-code={$issue['cusa_id']}'>I HAVE THIS GAME</a> --- <a href='{$issue['issue']}'>{$issue['cusa_id']} - {$issue['game_name']}</a></li>";
    }
    $html .= "</ul></body></html>";
    return $html;
}

file_put_contents("todo_linux.html", generateHtml("Missing issues for Linux", $todo_linux));
file_put_contents("todo_windows.html", generateHtml("Missing issues for Windows", $todo_windows));
file_put_contents("todo_macos.html", generateHtml("Missing issues for macOS", $todo_macos));

// Generate index.html linking to all three files
$index_html = <<<HTML
<html>
<head>
    <title>Missing shadPS4 Compatibility Reports</title>
       <style>
        body {
            font-family: Arial, sans-serif;
            background-color: white;
            color: black;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background-color: #121212;
                color: white;
            }
            a {
                color: #bb86fc;
            }
        }

        h2 {
            text-align: center;
        }
        ul {
            list-style-type: none;
            padding: 0;
        }
        li {
            padding: 8px;
        }
    </style>
</head>
<body>
    <h2>Missing <a href="https://github.com/shadps4-emu/shadps4">shadPS4</a> Compatibility Reports</h2>
    <p>Click the operating system on which you would like to make an issue for. If you have one of the games listed, you can be the first to create an issue for it.</p><hr>
    <ul>
        <li><a href="todo_linux.html">Missing issues for Linux</a></li>
        <li><a href="todo_windows.html">Missing issues for Windows</a></li>
        <li><a href="todo_macos.html">Missing issues for macOS</a></li>
    </ul>
    <p>While this does not contain a list of games missing from shadPS4 compatibility list (yet), it contains a list of games that ARE available on the compatibility list but don't have issues for every OS.<br><br>This list is updated daily via GitHub Actions.</p>
</body>
</html>
HTML;

file_put_contents("index.html", $index_html);
echo "Generated index.html\n";

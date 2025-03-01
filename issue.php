<?php

$options = getopt("c");

if (isset($options["c"]) && file_exists("issues.json")) {
    echo "Using cached issues.json...\n";
    $all_issues = json_decode(file_get_contents("issues.json"), true);
} else {
    echo "Fetching issues from GitHub...\n";
    $all_issues = [];
    $page = 1;

    do {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/shadps4-emu/shadps4-game-compatibility/issues?per_page=100&page=$page");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: PHP-Script", "Accept: application/vnd.github.v3+json"]);
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

    file_put_contents("issues.json", json_encode($all_issues, JSON_PRETTY_PRINT));
    echo "Fetched all issues, total: " . count($all_issues) . "\n";
}

$cusa_issues = [];
$status_labels = ["status-playable", "status-ingame", "status-menus", "status-boots", "status-nothing"];
$os_labels = ["os-windows", "os-linux", "os-macOS"];

// Step 1: Collect issues per CUSA
foreach ($all_issues as $issue) {
    if (preg_match('/CUSA\d{5}/', $issue["title"], $matches)) {
        $cusa_id = $matches[0];

        $title_parts = explode(" - ", $issue["title"]);
        $game = $title_parts[1] ?? "Unknown";

        $labels = array_column($issue["labels"], "name");
        $statuses = [];
        $oses = [];

        foreach ($status_labels as $label) {
            if (in_array($label, $labels)) {
                $statuses[] = str_replace("status-", "", $label);
            }
        }

        foreach ($os_labels as $label) {
            if (in_array($label, $labels)) {
                $oses[] = str_replace("os-", "", $label);
            }
        }

        if (!isset($cusa_issues[$cusa_id])) {
            $cusa_issues[$cusa_id] = [
                "game" => $game,
                "issues" => [],
                "os_present" => [],
                "cusa" => $cusa_id
            ];
        }

        $cusa_issues[$cusa_id]["issues"][] = [
            "url" => $issue["html_url"],
            "status" => implode(", ", $statuses),
            "os" => implode(", ", $oses),
            "cusa" => $cusa_id
        ];

        $cusa_issues[$cusa_id]["os_present"] = array_merge($cusa_issues[$cusa_id]["os_present"], $oses);
    }
}

// Step 2: Filter out issues based on missing OS
$todo_windows = [];
$todo_linux = [];
$todo_macos = [];

foreach ($cusa_issues as $cusa_id => $data) {
    $os_present = array_unique($data["os_present"]);

    if (!in_array("windows", $os_present)) {
        $todo_windows[$cusa_id] = $data;
    }
    if (!in_array("linux", $os_present)) {
        $todo_linux[$cusa_id] = $data;
    }
    if (!in_array("macOS", $os_present)) {
        $todo_macos[$cusa_id] = $data;
    }
}

// Step 3: Sort games alphabetically by title
function sortByGameName($a, $b) {
    return strcasecmp($a["game"], $b["game"]);
}

usort($todo_windows, "sortByGameName");
usort($todo_linux, "sortByGameName");
usort($todo_macos, "sortByGameName");

// Step 4: Generate HTML files
function genOsList($os, $data) {
    $html = "<html lang=\"en\"><head><title>Missing shadPS4 Compatibility Reports for $os</title>
    <link href=\"style.css\" rel=\"stylesheet\" /></head><body><h1>Missing <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4\">shadPS4</a> Compatibility Reports for $os</h1><p>Here's a list of games that don't yet have an issue for $os.<br><br>This list does not include <a href=\"https://serialstation.com\">every game</a>; if you have a game that is not in any <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues\">compatibility issues</a>, please <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues/new?template=game_compatibility.yml\">create a new blank issue</a>.<br><br><a href=\"./\">Test for another OS</a></p><br><hr><ul>";
        foreach ($data as $cusa_id => $info) {
            $game = $info["game"];
            $cusa = $info["cusa"];
            $html .= "<li><span><a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues?q=$cusa\">Search</a> | <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues/new?template=game_compatibility.yml&title={$cusa}%20-%20{$game}&game-name={$game}&game-code={$cusa}\"><b><i>I have this game</i></b></a> | {$cusa} &#x2022; {$game}";
            $html .= " (";
            $c = 0;
            foreach ($info["issues"] as $issue) {
                if ($c > 0) $html .= ", ";
                $c++;
                $html .= "<a target=\"_blank\" href=\"{$issue["url"]}\">status-{$issue["status"]} on os-{$issue["os"]}</a>";
            }
            $html .= ")</span></li>";
        }

    $html .= "</ul><hr><br><p><a href=\"./\">Test for another OS</a><br><br><br></p></body></html>";
    return $html;
}

file_put_contents("linux.html", genOsList("Linux", $todo_linux));
file_put_contents("windows.html", genOsList("Windows", $todo_windows));
file_put_contents("macos.html", genOsList("macOS", $todo_macos));

$index_html = <<<HTML
<html lang="en">
<head>
    <title>Missing shadPS4 Compatibility Reports</title>
    <link href="style.css" rel="stylesheet" />
</head>
<body>
    <h1>Missing <a target="_blank" href="https://github.com/shadps4-emu/shadps4">shadPS4</a> Compatibility Reports</h1>
    <p>Click the operating system on which you would like to make an issue for.<br>If you have one of the games listed, you can be the first to create an issue for it.</p><br><hr>
    <ul>
        <li><a href="linux.html">Missing issues for Linux</a></li>
        <li><a href="windows.html">Missing issues for Windows</a></li>
        <li><a href="macos.html">Missing issues for macOS</a></li>
    </ul>
    <hr><p><br>This list is updated daily via <a target="_blank" href="https://github.com/imnltsa/shadps4-todo/actions">GitHub Actions</a>.<br><br>Note: This does not show incorrectly named/tagged games.<br>Note: This does not include games that do not have any existing issues.<br><br><br></p>
</body>
</html>
HTML;

file_put_contents("index.html", $index_html);
echo "Done.";

<?php

$options = getopt("c");
$ftime = filemtime("style.css");

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

// Step 1: Collect issues per CUSA
foreach ($all_issues as $issue) {
    if (preg_match('/CUSA\d{5}/', $issue["title"], $matches)) {
        $cusa_id = $matches[0];

        $title_parts = explode(" - ", $issue["title"]);
        $game = $title_parts[1] ?? "Unknown";

        $labels = array_column($issue["labels"], "name");
        $statuses = [];
        $oses = [];

        foreach ($labels as $label) {
            if (strpos($label, "status-") === 0) {
                $statuses[] = str_replace("status-", "", $label);
            } elseif (strpos($label, "os-") === 0) {
                $oses[] = str_replace("os-", "", $label);
            }
        }

        $unique_id = uniqid();

        if (!isset($cusa_issues[$unique_id])) {
            $cusa_issues[$unique_id] = [
                "game" => $game,
                "issues" => [],
                "os_present" => [],
                "cusa" => $cusa_id,
                "number" => $issue["number"]
            ];
        }

        $cusa_issues[$unique_id]["issues"][] = [
            "url" => $issue["html_url"],
            "status" => implode(", ", $statuses),
            "os" => implode(", ", $oses),
            "number" => $issue["number"]
        ];

        $cusa_issues[$unique_id]["os_present"] = array_merge($cusa_issues[$unique_id]["os_present"], $oses);
    }
}

// Step 2: Filter out issues based on missing OS
$todo_windows = [];
$todo_linux = [];
$todo_macos = [];

foreach ($cusa_issues as $unique_id => $data) {
    $os_present = array_unique($data["os_present"]);
    $cusa_id = $data["cusa"];

    // Check if any instance of the CUSA has the respective OS
    $has_windows = false;
    $has_linux = false;
    $has_macos = false;

    foreach ($cusa_issues as $check_id => $check_data) {
        if ($check_data["cusa"] === $cusa_id) {
            if (in_array("windows", $check_data["os_present"])) {
                $has_windows = true;
            }
            if (in_array("linux", $check_data["os_present"])) {
                $has_linux = true;
            }
            if (in_array("macOS", $check_data["os_present"])) {
                $has_macos = true;
            }
        }
    }

    if (!$has_windows) {
        $todo_windows[$unique_id] = $data;
    }
    if (!$has_linux) {
        $todo_linux[$unique_id] = $data;
    }
    if (!$has_macos) {
        $todo_macos[$unique_id] = $data;
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
function genOsCompare($os, $data, $cusa_issues) {
    global $ftime;
    $tc = count($data);
    $html = "<html lang=\"en\"><head><title>Missing shadPS4 Compatibility Reports for $os</title>
    <link href=\"style.css?$ftime\" rel=\"stylesheet\" /></head><body><h1>Missing <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4\">shadPS4</a> Compatibility Reports for $os</h1><p>Here's a list of titles that don't yet have an issue for $os. If you have one of these games, press 'I have this title'.<br><br>This list does not include <a href=\"https://serialstation.com\">every title</a>; if you have a title that is not in any <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues\">compatibility issues</a>, please <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues/new?template=game_compatibility.yml\">create a new blank issue</a>.<br><br>Total missing issues for $os: $tc - <a href=\"index.html\">Test for another OS</a></p><br><hr><ul>";

    $seen_cusas = [];

    foreach ($data as $unique_id => $info) {
        $cusa = $info["cusa"];
        if (in_array($cusa, $seen_cusas)) {
            continue;
        }
        $seen_cusas[] = $cusa;

        if (in_array(strtolower($os), array_map('strtolower', $info["os_present"]))) {
            continue;
        }

        $game = $info["game"];
        $html .= "<li><span><a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues?q=$cusa\">Search</a> | <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues/new?template=game_compatibility.yml&title={$cusa}%20-%20{$game}&game-name={$game}&game-code={$cusa}\"><b><i>I have this title</i></b></a> | {$cusa} &#x2022; {$game}";
        $html .= " (";
        $c = 0;
        foreach ($cusa_issues as $check_id => $check_info) {
            if ($check_info["cusa"] === $cusa) {
                $tmpc=0;
                foreach ($check_info["issues"] as $issue) {
                    $tmpc++;
                    if ($c > 0) $html .= ", ";
                    $c++;
                    $html .= "<a target=\"_blank\" href=\"{$issue["url"]}\">{$issue["os"]} {$issue["status"]}</a>";
                }
                if($tmpc===0){$thtml.="untagged";}
            }
        }
        $html .= ")</span></li>";
    }

    $html .= "</ul><hr><br><p><a href=\"index.html\">Test for another OS</a><br><br><br></p></body></html>";
    return $html;
}

file_put_contents("linux_missing.html", genOsCompare("Linux", $todo_linux, $cusa_issues));
file_put_contents("windows_missing.html", genOsCompare("Windows", $todo_windows, $cusa_issues));
file_put_contents("macos_missing.html", genOsCompare("macOS", $todo_macos, $cusa_issues));

// hell time

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.github.com/repos/shadps4-emu/shadps4-game-compatibility/milestones?state=all");
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
    die("Failed to fetch milestones! HTTP code: $http_code\n");
}

$milestones = json_decode($response, true);
if (!is_array($milestones)) {
    die("JSON decoding error: " . json_last_error_msg() . "\nResponse:\n$response\n");
}

// Determine the latest milestone by ID
$latest_milestone = null;
foreach ($milestones as $milestone) {
    if ($latest_milestone === null || $milestone["id"] > $latest_milestone["id"]) {
        $latest_milestone = $milestone;
    }
}

$issues_before_latest_milestone = array_filter($all_issues, function($issue) use ($latest_milestone) {
    return isset($issue["milestone"]) && $issue["milestone"]["id"] < $latest_milestone["id"];
});

$issues_data = [];
foreach ($issues_before_latest_milestone as $issue) {
    if (preg_match('/CUSA\d{5}/', $issue["title"], $matches)) {
        $cusa = $matches[0];
        $title_parts = explode(" - ", $issue["title"]);
        $game_name = $title_parts[1] ?? "Unknown";

        // Extract OS labels
        $os_labels = array_filter($issue["labels"], function($label) {
            return strpos($label["name"], "os-") === 0;
        });
        $os = array_map(function($label) {
            return str_replace("os-", "", $label["name"]);
        }, $os_labels);

        $issues_data[] = [
            "issue_number" => $issue["number"],
            "cusa" => $cusa,
            "game_name" => $game_name,
            "os" => $os
        ];
    }
}
//file_put_contents("macos_outdated.html", $cusa_issues);
//die();// Sort issues_data alphabetically by game name
function sortByGameNameIssues($a, $b) {
    return strcasecmp($a["game_name"], $b["game_name"]);
}

usort($issues_data, "sortByGameNameIssues");

 $pwin=0;
 $pmac=0;
 $plin=0;
function genOsBacktrack($os, $data, $cusa_issues) {
    global $pwin, $pmac, $plin, $ftime;
    $filtered_data = array_filter($data, function($title) use ($os) {
        return in_array(strtolower($os), array_map('strtolower', $title["os"]));
    });

    // Count the filtered data
    $tc = count($filtered_data);
    $unplayable_count = 0;


    $seen_cusas = [];
    $thtml="";
    foreach ($data as $title) {
        $cusa = $title["cusa"];
        if (in_array($cusa, $seen_cusas)) {
            continue;
        }
        $seen_cusas[] = $cusa;

        $issueos = reset($title["os"]);
        if (strtolower($issueos) != strtolower($os)) continue;


        
        $game = $title["game_name"];
        $issuenum = $title["issue_number"];
        $is_playable = false;
        foreach ($cusa_issues as $check_id => $check_info) {
            if ($check_info["cusa"] === $cusa) {
                foreach ($check_info["issues"] as $issue) {
                    if (strtolower($issue["os"]) === strtolower($os) && strtolower($issue["status"]) === "playable") {
                        $is_playable = true;
                        break 2;
                    }
                }
            }
        }

        if (!$is_playable) {
            $unplayable_count++;
            if(strtolower($os) == "windows") $pwin++;
            if(strtolower($os) == "macos") $pmac++;
           // echo $pmac."\n";
            if(strtolower($os) == "linux") $plin++;
        } else {

        }

        $thtml .= "<li" . ($is_playable ? " title=\"This title is considered playable on $os\" class=\"grey\"" : "") . "><span><a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues?q=$cusa\">Search</a> | <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues/$issuenum\"><b><i>I have this title</i></b></a> | {$cusa} &#x2022; {$game}";
        $thtml .= " (";
        $c = 0;
        foreach ($cusa_issues as $check_id => $check_info) {
            if ($check_info["cusa"] === $cusa) {
                $tmpc=0;
                foreach ($check_info["issues"] as $issue) {
                    $tmpc++;
                    if ($c > 0) $thtml .= ", ";
                    $c++;
                    $thtml .= "<a target=\"_blank\" href=\"{$issue["url"]}\">{$issue["os"]} {$issue["status"]}</a>";
                }
                if($tmpc===0){$thtml.="untagged";}
            }
        }
        $thtml .= ")</span></li>";
    }
    $utc=0;
    if(strtolower($os) == "windows") $utc=$pwin;
    if(strtolower($os) == "macos") $utc=$pmac;
    if(strtolower($os) == "linux") $utc=$plin;
    $html = "<html lang=\"en\"><head><title>Outdated shadPS4 Compatibility Reports for $os</title>
    <link href=\"style.css?$ftime\" rel=\"stylesheet\" /></head><body><h1>Outdated <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4\">shadPS4</a> Compatibility Reports for $os</h1><p>Here's a list of titles that have issues for $os yet are outdated. If you have one of these games, press 'I have this title'.<br><br>This list does not include <a href=\"https://serialstation.com\">every title</a>; if you have a title that is not in any <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues\">compatibility issues</a>, please <a target=\"_blank\" href=\"https://github.com/shadps4-emu/shadps4-game-compatibility/issues/new?template=game_compatibility.yml\">create a new blank issue</a>.<br><br>
    Total outdated issues for $os: $utc <span title=\"Count including playable titles\" class=\"grey\">($tc)</span> - <a href=\"index.html\">Test for another OS</a></p><br><hr><ul>";

        $html .= $thtml;

    $html .= "</ul><hr><br><p><a href=\"index.html\">Test for another OS</a><br><br><br></p></body></html>";
    //$html = str_replace("$unplayable_count ($tc)", "$unplayable_count <span title=\"Count including playable titles\">($tc)</span>", $html);
    return $html;
}

file_put_contents("linux_outdated.html", genOsBacktrack("Linux", $issues_data, $cusa_issues));
file_put_contents("windows_outdated.html", genOsBacktrack("Windows", $issues_data, $cusa_issues));
file_put_contents("macos_outdated.html", genOsBacktrack("macOS", $issues_data, $cusa_issues));

$linux_missing_count = count($todo_linux);
$windows_missing_count = count($todo_windows);
$macos_missing_count = count($todo_macos);

$linux_outdated_count = count(array_filter($issues_data, function($issue) {
    return in_array("linux", array_map('strtolower', $issue["os"])) && strtolower(reset($issue["os"])) !== "playable";
}));
$windows_outdated_count = count(array_filter($issues_data, function($issue) {
    return in_array("windows", array_map('strtolower', $issue["os"])) && strtolower(reset($issue["os"])) !== "playable";
}));
$macos_outdated_count = count(array_filter($issues_data, function($issue) {
    return in_array("macos", array_map('strtolower', $issue["os"])) && strtolower(reset($issue["os"])) !== "playable";
}));


$index_html = <<<HTML
<html lang="en">
<head>
    <title>Missing shadPS4 Compatibility Reports</title>
    <link href="style.css?$ftime" rel="stylesheet" />
</head>
<body>
    <h1>Missing <a target="_blank" href="https://github.com/shadps4-emu/shadps4">shadPS4</a> Compatibility Reports</h1>
    <p>Click the operating system on which you would like to make an issue for.<br>If you have one of the games listed, you can be the first to create an issue for it.</p><br><hr>
    <ul>
        <li><a href="linux_missing.html">Missing issues for Linux</a> - $linux_missing_count</li>
        <li><a href="linux_outdated.html">Outdated issues for Linux</a> - $plin <span class="grey" title="Count including playable titles">($linux_outdated_count)</span></li>
        <li><a href="windows_missing.html">Missing issues for Windows</a> - $windows_missing_count</li>
        <li><a href="windows_outdated.html">Outdated issues for Windows</a> - $pwin <span class="grey" title="Count including playable titles">($windows_outdated_count)</span></li>
        <li><a href="macos_missing.html">Missing issues for macOS</a> - $macos_missing_count</li>
        <li><a href="macos_outdated.html">Outdated issues for macOS</a> - $pmac <span class="grey" title="Count including playable titles">($macos_outdated_count)</span></li>
    </ul>
    <hr><p><br>This list is updated daily via <a target="_blank" href="https://github.com/imnltsa/shadps4-todo/actions">GitHub Actions</a>.<br><br>Note: This does not show incorrectly named/tagged games.<br>Note: This does not include games that do not have any existing issues.<br><br><br></p>
</body>
</html>
HTML;

file_put_contents("index.html", $index_html);
echo "Done.";

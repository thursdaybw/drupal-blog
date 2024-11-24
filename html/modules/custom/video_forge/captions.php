<?php
if ($argc !== 3) {
    echo "Usage: php captions.php input.json output.ass\n";
    exit(1);
}

$jsonFile = $argv[1];
$outputFile = $argv[2];

// Read and parse the JSON file
$jsonContent = file_get_contents($jsonFile);
$data = json_decode($jsonContent, true);

if (!$data) {
    echo "Error: Failed to parse JSON file.\n";
    exit(1);
}

// Define styles
$styles = [
    "MrBeastDefault" => [
        "Fontname" => "Impact",
        "Fontsize" => "80",
        "PrimaryColour" => "&H00FFFFFF", // White
        "SecondaryColour" => "&H000000", // Not used
        "OutlineColour" => "&H00000000",
        "BackColour" => "&H00000000",
        "Bold" => "-1",
        "Italic" => "0",
        "Underline" => "0",
        "StrikeOut" => "0",
        "ScaleX" => "100",
        "ScaleY" => "100",
        "Spacing" => "-20",
        "Angle" => "0",
        "BorderStyle" => "1",
        "Outline" => "3",
        "Shadow" => "1",
        "Alignment" => "2",
        "MarginL" => "200",
        "MarginR" => "200",
        "MarginV" => "100",
        "Encoding" => "1"
    ],
    "MrBeastHighlight" => [
        "Fontname" => "Anton SC",
        "Fontsize" => "80",
        "PrimaryColour" => "&H00FF0000", // Red
        "SecondaryColour" => "&H000000", // Not used
        "OutlineColour" => "&H0000FF00",
        "BackColour" => "&H000000FF",
        "Bold" => "0",
        "Italic" => "0",
        "Underline" => "0",
        "StrikeOut" => "0",
        "ScaleX" => "100",
        "ScaleY" => "100",
        "Spacing" => "-20",
        "Angle" => "0",
        "BorderStyle" => "1",
        "Outline" => "10",
        "Shadow" => "1",
        "Alignment" => "2",
        "MarginL" => "200",
        "MarginR" => "200",
        "MarginV" => "100",
        "Encoding" => "1"
    ]
];

// Generate ASS header
$assHeader = "[Script Info]
Title: Phrase-timed Subtitles
ScriptType: v4.00+
PlayResX: 1920
PlayResY: 1080

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
";

foreach ($styles as $styleName => $styleProps) {
    $assHeader .= "Style: $styleName," . implode(',', $styleProps) . "\n";
}

$assEvents = [];

// Process each segment
foreach ($data["segments"] as $segment) {
    $words = $segment["words"];
    foreach ($words as $index => $wordInfo) {
        $currentWord = $wordInfo["word"];
        $startTime = gmdate("H:i:s", floor($wordInfo["start"])) . '.' . sprintf('%02d', ($wordInfo["start"] - floor($wordInfo["start"])) * 100);
        $endTime = gmdate("H:i:s", floor($wordInfo["end"])) . '.' . sprintf('%02d', ($wordInfo["end"] - floor($wordInfo["end"])) * 100);

        // Build the line with highlight for the current word
        $highlightedLine = "";
        foreach ($words as $i => $info) {
            $word = $info["word"];
            if ($i === $index) {
                // Add highlight tags for the current word
                $highlightedLine .= "{\\rMrBeastHighlight}$word{\\r}";
            } else {
                // Add other words without highlighting
                $highlightedLine .= " $word";
            }
        }
        $highlightedLine = $highlightedLine; // Clean up extra spaces

        // Add fade-in effect to the first line
        if ($index === 0) {
            $highlightedLine = "{\\fad(500,0)}" . $highlightedLine;
        }

        // Add the dialogue line
        $assEvents[] = "Dialogue: 0,{$startTime},{$endTime},MrBeastDefault,,0,0,0,,$highlightedLine";
    }
}


// Combine header and events
$assContent = $assHeader . "\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" . implode("\n", $assEvents);

// Save the ASS file with UTF-8 BOM encoding
file_put_contents($outputFile, "\xEF\xBB\xBF" . $assContent);

echo "Phrase-timed ASS file created: {$outputFile}\n";


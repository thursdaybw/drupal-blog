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
        "Fontname" => "Anton SC",
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
        "Shadow" => "0",
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
        "Shadow" => "0",
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

// Define chunking parameters
$maxWords = 6;
$maxDuration = 2.0; // Max duration for each chunk (seconds)
$pauseThreshold = 0.3; // Minimum pause duration to split chunks (seconds)

$assEvents = [];

// Process each segment with chunking
foreach ($data["segments"] as $segment) {
    $words = $segment["words"];
    $chunkStart = $words[0]["start"];
    $chunkWords = [];
    $wordCount = 0;

    foreach ($words as $index => $wordInfo) {
        $word = $wordInfo["word"];
        $start = $wordInfo["start"];
        $end = $wordInfo["end"];

        $chunkWords[] = $wordInfo; // Add word to the current chunk
        $wordCount++;

        $nextWordStart = isset($words[$index + 1]) ? $words[$index + 1]["start"] : null;
        $chunkDuration = $end - $chunkStart;
        $pauseDuration = $nextWordStart ? $nextWordStart - $end : null;

        // Check if chunk conditions are met
        if ($wordCount >= $maxWords || $chunkDuration >= $maxDuration || ($pauseDuration && $pauseDuration >= $pauseThreshold) || !$nextWordStart) {
            // Generate the text for the chunk with highlighted word
            foreach ($chunkWords as $currentIndex => $currentWordInfo) {
                $highlightedLine = ""; // Add fade effect for the first line
		if ($currentIndex === 0) {
                    $highlightedLine .= "{\\fad(500,0)}"; // Add fade effect for the first line
		}
                foreach ($chunkWords as $j => $info) {
                    $currentWord = $info["word"];
                    if ($j === $currentIndex) {
                        $highlightedLine .= "{\\rMrBeastHighlight}$currentWord{\\r}";
                    } else {
                        $highlightedLine .= " $currentWord";
                    }
                }

                // Format start and end times for the chunk
                $chunkStartTime = gmdate("H:i:s", floor($currentWordInfo["start"])) . '.' . sprintf('%02d', ($currentWordInfo["start"] - floor($currentWordInfo["start"])) * 100);
                $chunkEndTime = gmdate("H:i:s", floor($currentWordInfo["end"])) . '.' . sprintf('%02d', ($currentWordInfo["end"] - floor($currentWordInfo["end"])) * 100);

                // Create the dialogue line
                $assEvents[] = "Dialogue: 0,{$chunkStartTime},{$chunkEndTime},MrBeastDefault,,0,0,0,,$highlightedLine";
            }

            // Reset for the next chunk
            $chunkStart = $nextWordStart;
            $chunkWords = [];
            $wordCount = 0;
        }
    }
}

// Combine header and events
$assContent = $assHeader . "\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" . implode("\n", $assEvents);

// Save the ASS file with UTF-8 BOM encoding
file_put_contents($outputFile, "\xEF\xBB\xBF" . $assContent);

echo "Phrase-timed ASS file created: {$outputFile}\n";


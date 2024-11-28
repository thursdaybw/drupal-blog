<?php

$options = getopt('', ['style:']); // Parse named options like --style

// Remove the script name and named options from $argv to get positional arguments.
$args = array_values(array_filter($argv, function ($arg) {
    return strpos($arg, '--') !== 0; // Exclude named options like --style
}));

// Validate required positional arguments.
if (count($args) < 3) {
    echo "Usage: php captions.php [--style=<style>] input.json output.ass\n";
    exit(1);
}

// Extract positional arguments.
$jsonFile = $args[1]; // First positional argument
$outputFile = $args[2]; // Second positional argument

// Validate the style option or use the default.
$selectedStyle = $options['style'] ?? 'GreenAndGold';

// Log the selected style (useful for debugging).
error_log("Selected style: $selectedStyle");

// Validate the style against allowed styles.
$allowed_styles = ['GreenAndGold', 'MrBeast', 'NeonGlow', 'BoldShadow', 'ClassicBlue'];
if (!in_array($selectedStyle, $allowed_styles, true)) {
    fwrite(STDERR, "Error: Invalid style '$selectedStyle'. Allowed styles are: " . implode(', ', $allowed_styles) . "\n");
    exit(1);
}

// Validate the JSON file.
if (!file_exists($jsonFile) || !is_readable($jsonFile)) {
    echo "Error: JSON file '$jsonFile' does not exist or is not readable.\n";
    exit(1);
}

// Read and parse the JSON file.
$jsonContent = file_get_contents($jsonFile);
$data = json_decode($jsonContent, true);

if (!$data) {
    echo "Error: Failed to parse JSON file.\n";
    exit(1);
}

// Your existing logic for processing the JSON file and generating the ASS file goes here.


if (!$data) {
    echo "Error: Failed to parse JSON file.\n";
    exit(1);
}

// Define base styles and overrides
$styles = [
    "MrBeast" => [
        "Default" => [
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
            "MarginV" => "300",
            "Encoding" => "1"
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H00FF0000", // Red
            "OutlineColour" => "&H0000FF00",
            "BackColour" => "&H000000FF",
            "Outline" => "10"
        ],
        "SecondaryHighlight" => [
            "PrimaryColour" => "&H00FFFF00", // Yellow
            "OutlineColour" => "&H00FF00FF",
            "BackColour" => "&H00000000",
            "Outline" => "8"
        ]
    ],
    "NeonGlow" => [
        "Default" => [
            "Fontname" => "Impact",
            "Fontsize" => "80",
	    "PrimaryColour" => "&H00FFFFFF",
            "SecondaryColour" => "&H000000", // Not used
	    "OutlineColour" => "&H00FF4500",
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
            "Outline" => "5",
            "Shadow" => "2",
            "Alignment" => "2",
            "MarginL" => "200",
            "MarginR" => "200",
            "MarginV" => "300",
            "Encoding" => "1"
        ],
	"PrimaryHighlight" => [
            "PrimaryColour" => "&H00FFFF00", // Neon yellow
            "OutlineColour" => "&H00FF00FF",
            "Outline" => "10"
        ]
    ],
    "GreenAndGold" => [
        "Default" => [
            "Fontname" => "Anton SC",
            "Fontsize" => "70",
            "PrimaryColour" => "&H00FFFFFF",
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
            "Outline" => "5",
            "Shadow" => "4",
            "Alignment" => "2",
            "MarginL" => "200",
            "MarginR" => "200",
            "MarginV" => "300",
            "Encoding" => "1"
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H0029F602", // Green 
            "Shadow" => "6"
        ],
        "SecondaryHighlight" => [
	    "PrimaryColour" => "&H000aeaf1", // Gold 
            "BackColour" => "&H00000000",
            "Outline" => "8"
        ]
    ],
    "BoldShadow" => [
        "Default" => [
            "Fontname" => "Anton SC",
            "Fontsize" => "70",
            "PrimaryColour" => "&H00FFFFFF",
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
            "Outline" => "5",
            "Shadow" => "4",
            "Alignment" => "2",
            "MarginL" => "200",
            "MarginR" => "200",
            "MarginV" => "300",
            "Encoding" => "1"
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H00FF4500", // Orange
            "Shadow" => "6"
        ],
        "SecondaryHighlight" => [
	    "PrimaryColour" => "&H0029F602", // Hormozi Green
            //"OutlineColour" => "&H00F00FF",
            "BackColour" => "&H00000000",
            "Outline" => "8"
        ]
    ],
    "ClassicBlue" => [
        "Default" => [
            "Fontname" => "Ariel",
            "Fontsize" => "80",
            "PrimaryColour" => "&H00FFFFFF",
            "SecondaryColour" => "&H000000", // Not used
            "OutlineColour" => "&H000000FF",
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
            "MarginV" => "200",
            "Encoding" => "1"
        ],
	"PrimaryHighlight" => [
            "PrimaryColour" => "&H00FF0000", // Red
            "OutlineColour" => "&H0000FF00"
        ]
    ],

];

// Helper function to merge styles
function mergeStyles($baseStyle, $overrides) {
    // Ensure that overrides only affect the base style for the selected style
    foreach ($overrides as $key => $value) {
        $baseStyle[$key] = $value; // Replace base style values with overrides
    }
    return $baseStyle;
}

// Generate ASS header
$assHeader = "[Script Info]
Title: Phrase-timed Subtitles
ScriptType: v4.00+
PlayResX: 1920
PlayResY: 1080

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
";

foreach ($styles[$selectedStyle] as $styleName => $overrides) {
    $finalStyle = mergeStyles($styles[$selectedStyle]["Default"], $overrides);

    // Convert associative array to ordered style string
    $styleString = implode(',', array_values($finalStyle));

    // Add style to header
    $assHeader .= "Style: $styleName,$styleString\n";
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

	    foreach ($chunkWords as $currentIndex => $currentWordInfo) {

                $highlightedLine = ""; // Add fade effect for the first line
		if ($currentIndex === 0) {
                    $highlightedLine .= "{\\fad(500,0)}"; // Add fade effect for the first line
		}

		// Generate the text for the chunk with highlighted word
		// Process chunks with alternating highlights
		$highlightCounter = 0; 
                foreach ($chunkWords as $j => $info) {
                    $currentWord = $info["word"];

		    // Check if SecondaryHighlight is defined in styles
		    $useSecondaryHighlight = isset($styles[$selectedStyle]["SecondaryHighlight"]);

		    // Use primary style for 4 words, then secondary for 1 word if defined
		    if ($useSecondaryHighlight) {
			    $highlightStyle = ($highlightCounter % 5 === 4) ? "SecondaryHighlight" : "PrimaryHighlight";
		    } else {
			    // Fallback to primary style if secondary is not defined
			    $highlightStyle = "PrimaryHighlight";
		    }


		    if ($j === $currentIndex) {
		      $highlightedLine .= "{\\r$highlightStyle}$currentWord{\\r}";
		    } else {
		      $highlightedLine .= " $currentWord";
		    }

		    $highlightCounter++;
                }

                // Format start and end times for the chunk
                $chunkStartTime = gmdate("H:i:s", floor($currentWordInfo["start"])) . '.' . sprintf('%02d', ($currentWordInfo["start"] - floor($currentWordInfo["start"])) * 100);
                $chunkEndTime = gmdate("H:i:s", floor($currentWordInfo["end"])) . '.' . sprintf('%02d', ($currentWordInfo["end"] - floor($currentWordInfo["end"])) * 100);

                // Create the dialogue line
                $assEvents[] = "Dialogue: 0,{$chunkStartTime},{$chunkEndTime},Default,,0,0,0,,$highlightedLine";
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

if (file_exists($outputFile)) {
    unlink($outputFile); // Remove the existing file.
}

// Save the ASS file with UTF-8 BOM encoding
file_put_contents($outputFile, "\xEF\xBB\xBF" . $assContent);

echo "Phrase-timed ASS file created: {$outputFile}\n";


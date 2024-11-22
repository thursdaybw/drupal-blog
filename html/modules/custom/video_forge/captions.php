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

// Font sizes
$fontsize_l = "90"; // Highlighted word font size
$fontsize_s = "80"; // Default font size

// Define presets with animations and additional settings
$presets = [
    "Hormozi" => [
        "style" => "Style: Hormozi,Anton SC,$fontsize_s,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,110,90,-20,0,1,4,1,8,200,200,700,1",
        "highlight" => [
            "color" => "&H29F602", // Bright green
            "size" => $fontsize_l,
            "bold" => true
        ],
        "animation" => "fade-in"
    ],
    "MrBeast" => [
        "style" => "Style: MrBeast,Impact,$fontsize_s,&H00FFFF00,&H00FFFF00,&H00000000,&H00000000,-1,0,0,0,100,100,-20,0,1,3,1,2,200,200,700,1",
        "highlight" => [
            "color" => "&H0000FF", // Blue
            "size" => $fontsize_l,
            "bold" => true
        ],
        "animation" => "none"
    ],
    "GaryVee" => [
        "style" => "Style: GaryVee,Montserrat,$fontsize_s,&H00FF4500,&H00FF4500,&H00000000,&H00000000,-1,0,0,0,100,90,-10,0,1,2,1,2,200,200,700,1",
        "highlight" => [
            "color" => "&HFF0000", // Red
            "size" => $fontsize_l,
            "bold" => true
        ],
        "animation" => "slide-up"
    ],
    "FadeIn" => [
        "style" => "Style: FadeIn,Anton SC,$fontsize_s,&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,100,70,-20,0,1,5,0,8,200,200,700,1",
        "highlight" => [
            "color" => "&HFFFF00", // Yellow
            "size" => $fontsize_l,
            "bold" => true
        ],
        "animation" => "fade-in"
    ],
    "ShadowYellow" => [
        "style" => "Style: ShadowYellow,Anton SC,$fontsize_s,&H00FFFF00,&H00FFFF00,&H00000000,&H00000000,-1,0,0,0,100,70,-30,0,1,2,3,2,200,200,700,1",
        "highlight" => [
            "color" => "&HFFFF00", // Yellow
            "size" => $fontsize_l,
            "bold" => false
        ],
        "animation" => "none"
    ]
];

// Choose a preset
$chosenPresetKey = "Hormozi"; // Change this to select a different preset
$chosenPreset = $presets[$chosenPresetKey];

// Generate ASS header
$assHeader = "[Script Info]
Title: Phrase-timed Subtitles
ScriptType: v4.00+
PlayResX: 1920
PlayResY: 1080

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
" . $chosenPreset['style'] . "\n";

// Define maximum words and duration for a sub-phrase
$maxWords = 6;
$maxDuration = 2.0; // Max duration for each phrase (seconds)
$pauseThreshold = 0.3; // Minimum pause to trigger a split (seconds)

$assEvents = [];

foreach ($data["segments"] as $segment) {
    $words = $segment["words"];
    $subPhraseStart = $words[0]["start"];
    $subPhraseWords = [];
    $wordCount = 0;

    foreach ($words as $i => $wordInfo) {
        $word = $wordInfo["word"];
        $start = $wordInfo["start"];
        $end = $wordInfo["end"];

        // Add the current word to the sub-phrase array
        $subPhraseWords[] = $wordInfo;
        $wordCount++;

        // Check for the next word to determine split conditions
        $nextWordStart = isset($words[$i + 1]) ? $words[$i + 1]["start"] : null;
        $subPhraseDuration = $end - $subPhraseStart;
        $pauseDuration = $nextWordStart ? $nextWordStart - $end : null;

        // If split conditions are met, create an ASS event for the sub-phrase
        if ($wordCount >= $maxWords || $subPhraseDuration >= $maxDuration || ($pauseDuration && $pauseDuration >= $pauseThreshold) || !$nextWordStart) {
            // Generate the text for this sub-phrase, with the currently spoken word highlighted
            foreach ($subPhraseWords as $j => $subWordInfo) {
		    $highlightedPhrase = "";
		    if ($j === 0) {
		      $highlightedPhrase = "{\\fad(500,0)}";
		    } 

		    foreach ($subPhraseWords as $k => $otherWordInfo) {
			    $otherWord = $otherWordInfo["word"];
			    if ($j === $k) {
				    // Apply highlight style to the current word
				    $highlightedPhrase .= "{\\c{$chosenPreset['highlight']['color']}\\fs{$chosenPreset['highlight']['size']}\\b" .
					    ($chosenPreset['highlight']['bold'] ? "1" : "0") .
					    "}{$otherWord}{\\c&HFFFFFF&\\fs$fontsize_s\\b0}";
			    } else {
				    // Default style for non-highlighted words
				    $highlightedPhrase .= "{$otherWord}";
			    }
		    }

                // Apply animation effect
                $animationEffect = "";
                if ($chosenPreset['animation'] === "fade-in") {
                    $animationEffect = "\\fad(500,0)"; // 500ms fade-in, no fade-out
                }

                // Format start and end times for ASS
                $subStartTime = gmdate("H:i:s", floor($subWordInfo["start"])) . '.' . sprintf('%02d', ($subWordInfo["start"] - floor($subWordInfo["start"])) * 100);
                $subEndTime = gmdate("H:i:s", floor($subWordInfo["end"])) . '.' . sprintf('%02d', ($subWordInfo["end"] - floor($subWordInfo["end"])) * 100);

                // Create ASS dialogue line
		// Create ASS dialogue line
		$assEvent = "Dialogue: 0,{$subStartTime},{$subEndTime},{$chosenPresetKey},,0,0,0,,{$highlightedPhrase}";
		$assEvents[] = $assEvent;
	    }

            // Reset for the next sub-phrase
            $subPhraseStart = $nextWordStart; // Reset start time for the next phrase
            $subPhraseWords = []; // Clear sub-phrase words
            $wordCount = 0;
        }
    }
}

// Combine header and events
$assContent = $assHeader . "\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" . implode("\n", $assEvents);

// Save the ASS file with UTF-8 BOM encoding
file_put_contents($outputFile, "\xEF\xBB\xBF" . $assContent);

echo "Phrase-timed ASS file with {$chosenPresetKey} preset and animations has been created: {$outputFile}\n";


<?php



namespace Drupal\video_forge\Subtitle;

/**
 * Class AssSubtitleGenerator
 * Handles the generation of ASS subtitle files from Whisper JSON files.
 */
class AssSubtitleGenerator {

  /**
   * Preset styles for ASS file generation.
   */
private $styles = [
    "baseDefault" => [
        "Fontname" => "Arial",
        "Fontsize" => "70",
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
        "Encoding" => "1",
    ],
    "MrBeast" => [
	"type" => "sequence",
        "Default" => [
            "Fontname" => "Anton SC",
            "Fontsize" => "80",
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H00FF0000", // Red
            "OutlineColour" => "&H0000FF00",
        ],
        "SecondaryHighlight" => [
            "PrimaryColour" => "&H00FFFF00", // Yellow
            "OutlineColour" => "&H00FF00FF",
        ],
    ],
    "NeonGlow" => [
	"type" => "sequence",
        "Default" => [
            "Fontname" => "Impact",
            "OutlineColour" => "&H00FF4500",
            "Shadow" => "2",
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H00FFFF00", // Neon yellow
            "OutlineColour" => "&H00FF00FF",
        ],
    ],
    "GreenAndGold" => [
	"type" => "sequence",
        "Default" => [
            "Fontname" => "Anton SC",
            "Outline" => "5",
            "Shadow" => "4",
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H0029F602", // Green
        ],
        "SecondaryHighlight" => [
            "PrimaryColour" => "&H000aeaf1", // Gold
        ],
    ],
    "BoldShadow" => [
	"type" => "sequence",
        "Default" => [
            "Fontname" => "Anton SC",
            "Shadow" => "4",
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H00FF4500", // Orange
        ],
        "SecondaryHighlight" => [
            "PrimaryColour" => "&H0029F602", // Hormozi Green
        ],
    ],
    "ClassicBlue" => [
	"type" => "sequence",
        "Default" => [
            "Fontname" => "Arial",
            "OutlineColour" => "&H000000FF", // Blue outline
        ],
        "PrimaryHighlight" => [
            "PrimaryColour" => "&H00FF0000", // Red
        ],
    ],
    "KaraokeClassic" => [
	    "type" => "karaoke", // Standard karaoke style
	    "Default" => [
		    "Fontname" => "Arial",
		    "Fontsize" => "70",
		    "PrimaryColour" => "&H00FFFFFF",
		    "SecondaryColour" => "&H00FFFF00", // Used by ASS karaoke
		    "OutlineColour" => "&H00000000",
		    "BackColour" => "&H00000000",
		    "Bold" => "-1",
		    "Italic" => "0",
		    "Underline" => "0",
		    "StrikeOut" => "0",
		    "ScaleX" => "100",
		    "ScaleY" => "100",
		    "Spacing" => "0",
		    "Angle" => "0",
		    "BorderStyle" => "1",
		    "Outline" => "2",
		    "Shadow" => "0",
		    "Alignment" => "2",
		    "MarginL" => "200",
		    "MarginR" => "200",
		    "MarginV" => "200",
		    "Encoding" => "1"
	    ],
    ],
   "KaraokeClassic" => [
        "type" => "karaoke", // Standard karaoke style
        "Default" => [
            "Fontname" => "Arial",
            "Fontsize" => "70",
            "PrimaryColour" => "&H00FFFFFF",
            "SecondaryColour" => "&H00FFFF00", // Used by ASS karaoke
            "Outline" => "2",
        ],
    ],
    "PlainStyle" => [
        "type" => "plain", // Simple subtitles with no effects
        "Default" => [
            "Fontname" => "Verdana",
            "Fontsize" => "60",
            "PrimaryColour" => "&H00FFFFFF",
            "OutlineColour" => "&H000000FF",
        ],
    ],
];

  /**
   * Parameters for chunking.
   */
  private $maxWords = 6;
  private $maxDuration = 2.0;
  private $pauseThreshold = 0.3;

  /**
   * Generate an ASS file.
   *
   * @param string $inputJsonPath
   *   Path to the Whisper JSON file.
   * @param string $outputAssPath
   *   Path to save the ASS file.
   * @param string $selectedStyle
   *   The style key to use.
   *
   * @throws \Exception
   */
  public function generateAssFromJson(string $inputJsonPath, string $outputAssPath, string $selectedStyle = 'GreenAndGold') {
    if (!file_exists($inputJsonPath)) {
      throw new \Exception("JSON file does not exist: $inputJsonPath");
    }

    $jsonContent = file_get_contents($inputJsonPath);
    $data = json_decode($jsonContent, true);

    if (!$data) {
      throw new \Exception("Failed to parse JSON file.");
    }

    if (!isset($this->styles[$selectedStyle])) {
      throw new \InvalidArgumentException("Invalid style: $selectedStyle");
    }

    $assContent = $this->generateAssContent($data, $selectedStyle);

    if (file_exists($outputAssPath)) {
      unlink($outputAssPath);
    }

    \Drupal::logger('video_forge')->error('Writing file @outputAssPath', [
      '@outputAssPath' => $outputAssPath,
    ]);

    file_put_contents($outputAssPath, "\xEF\xBB\xBF" . $assContent);
  }

  /**
   * Generate ASS content.
   */
  private function generateAssContent(array $data, string $selectedStyle): string {
    $chosenStyle = $this->styles[$selectedStyle];

    $assHeader = $this->generateAssHeader($chosenStyle);
    $assEvents = $this->generateAssEvents($data, $chosenStyle);

    return $assHeader . "\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n" . implode("\n", $assEvents);
  }

  private function generateAssHeader(array $style): string {
    $header = "[Script Info]
Title: Phrase-timed Subtitles
ScriptType: v4.00+
PlayResX: 1920
PlayResY: 1080

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
";

    // Use the "Default" entry in the current style as the base default.
    $baseDefault = $style['Default'];

    foreach ($style as $styleName => $overrides) {
        // Skip the 'type' entry in the style definition
        if ($styleName === 'type') {
            continue;
        }

        // Merge with the base default and the current style overrides
        $finalStyle = $this->mergeStyles($baseDefault, $overrides);

        // Convert the final style array to a string for ASS
        $styleString = implode(',', array_values($finalStyle));
        $header .= "Style: $styleName,$styleString\n";
    }

    return $header;
}



private function generateAssEvents(array $data, array $style): array {
    $assEvents = [];

    switch ($style['type']) {
        case 'sequence':
            // Existing sequenced dialogue logic
            foreach ($data["segments"] as $segment) {
                $words = $segment["words"];
                $chunkStart = $words[0]["start"];
                $chunkWords = [];
                $wordCount = 0;

                foreach ($words as $index => $wordInfo) {
                    $chunkWords[] = $wordInfo;
                    $wordCount++;

                    $nextWordStart = $words[$index + 1]["start"] ?? null;
                    $chunkDuration = $wordInfo["end"] - $chunkStart;
                    $pauseDuration = $nextWordStart ? $nextWordStart - $wordInfo["end"] : null;

                    if ($wordCount >= $this->maxWords || $chunkDuration >= $this->maxDuration || ($pauseDuration && $pauseDuration >= $this->pauseThreshold) || !$nextWordStart) {
                        $this->addAssEvent($chunkWords, $style, $assEvents, true);
                        $chunkStart = $nextWordStart;
                        $chunkWords = [];
                        $wordCount = 0;
                    }
                }
            }
            break;

        case 'karaoke':
            // Karaoke logic using ASS built-in `k` overrides
            foreach ($data["segments"] as $segment) {
                $start = gmdate("H:i:s", floor($segment["start"])) . '.' . sprintf('%02d', ($segment["start"] - floor($segment["start"])) * 100);
                $end = gmdate("H:i:s", floor($segment["end"])) . '.' . sprintf('%02d', ($segment["end"] - floor($segment["end"])) * 100);

                $karaokeLine = "";
                foreach ($segment["words"] as $word) {
                    $duration = round(($word["end"] - $word["start"]) * 100); // Convert to centiseconds
                    $karaokeLine .= "{\\k{$duration}}" . $word["word"] . " ";
                }

                $assEvents[] = "Dialogue: 0,{$start},{$end},Default,,0,0,0,,{$karaokeLine}";
            }
            break;

        case 'plain':
            // Plain subtitles with no effects
            foreach ($data["segments"] as $segment) {
                $start = gmdate("H:i:s", floor($segment["start"])) . '.' . sprintf('%02d', ($segment["start"] - floor($segment["start"])) * 100);
                $end = gmdate("H:i:s", floor($segment["end"])) . '.' . sprintf('%02d', ($segment["end"] - floor($segment["end"])) * 100);

                $text = implode(" ", array_column($segment["words"], "word"));

                $assEvents[] = "Dialogue: 0,{$start},{$end},Default,,0,0,0,,{$text}";
            }
            break;
    }

    return $assEvents;
}


private function addAssEvent(array $chunkWords, array $style, array &$assEvents, bool $applyFade) {
    $highlightCounter = 0; // Counter to track when to apply secondary highlight.

    foreach ($chunkWords as $index => $wordInfo) {
        $highlightedLine = "";

        // Apply fade effect only to the first dialogue entry in the sequence.
        if ($applyFade && $index === 0) {
            $highlightedLine .= "{\\fad(500,0)}";
        }

        // Build the dialogue with highlighting for the current word.
        foreach ($chunkWords as $wordIndex => $otherWordInfo) {
            $otherWord = $otherWordInfo["word"];

            // Check if SecondaryHighlight is defined in styles.
            $useSecondaryHighlight = isset($style["SecondaryHighlight"]);

            // Use primary style for 4 words, then secondary for 1 word if defined.
            if ($useSecondaryHighlight) {
                $highlightStyle = ($highlightCounter % 5 === 4) ? "SecondaryHighlight" : "PrimaryHighlight";
            } else {
                // Fallback to primary style if secondary is not defined.
                $highlightStyle = "PrimaryHighlight";
            }

            if ($index === $wordIndex) {
                // Apply highlight style to the current word.
                $highlightedLine .= "{\\r$highlightStyle}{$otherWord}{\\r}";
            } else {
                // Default style for non-highlighted words.
                $highlightedLine .= " {$otherWord}";
            }

            $highlightCounter++;
        }

        $start = gmdate("H:i:s", floor($wordInfo["start"])) . '.' . sprintf('%02d', ($wordInfo["start"] - floor($wordInfo["start"])) * 100);
        $end = gmdate("H:i:s", floor($wordInfo["end"])) . '.' . sprintf('%02d', ($wordInfo["end"] - floor($wordInfo["end"])) * 100);

        $assEvents[] = "Dialogue: 0,{$start},{$end},Default,,0,0,0,,$highlightedLine";

        $applyFade = false; // Ensure fade is applied only to the first entry.
    }
}

  /**
   * Merge styles.
   */
  private function mergeStyles(array $baseStyle, array $overrides): array {
    foreach ($overrides as $key => $value) {
      $baseStyle[$key] = $value;
    }
    return $baseStyle;
  }
}


<?php

namespace Drupal\video_forge\Subtitle;

use Drupal\video_forge\Entity\CaptionStyle;

/**
 * Class AssSubtitleGenerator
 * Handles the generation of ASS subtitle files from Whisper JSON files.
 */
class AssSubtitleGenerator {

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
   *   The machine name of the style configuration entity to use.
   *
   * @throws \Exception
   */
  public function generateAssFromJson(string $inputJsonPath, string $outputAssPath, string $selectedStyle) {
    if (!file_exists($inputJsonPath)) {
      throw new \Exception("JSON file does not exist: $inputJsonPath");
    }

    $jsonContent = file_get_contents($inputJsonPath);
    $data = json_decode($jsonContent, true);

    if (!$data) {
      throw new \Exception("Failed to parse JSON file.");
    }

    // Load the selected style configuration entity.
    /** @var \Drupal\video_forge\Entity\CaptionStyle $styleEntity */
    $styleEntity = \Drupal::entityTypeManager()
      ->getStorage('caption_style')
      ->load($selectedStyle);

    if (!$styleEntity) {
      throw new \InvalidArgumentException("Invalid style: $selectedStyle");
    }

    // Convert the entity to an array for use in ASS generation.
    $chosenStyle = $styleEntity->toArray();
    echo "chosenStyle: " . print_r($chosenStyle, 1);

    $assContent = $this->generateAssContent($data, $chosenStyle);

    if (file_exists($outputAssPath)) {
      unlink($outputAssPath);
    }

    file_put_contents($outputAssPath, "\xEF\xBB\xBF" . $assContent);
  }

  /**
   * Generate ASS content.
   */
  private function generateAssContent(array $data, array $chosenStyle): string {


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

    // Add the base/default style.
    $fields = CaptionStyle::getFieldDefinitions();
    $baseStyleString = $this->buildStyleString($style, $fields);
    $header .= "Style: Default{$baseStyleString}\n";

    // Add styles for PrimaryHighlight and SecondaryHighlight.
    foreach (['primaryHighlight', 'secondaryHighlight'] as $highlightKey) {
        if (!empty($style[$highlightKey])) {
            // Merge the base style with highlight overrides.
            $highlightOverrides = $style[$highlightKey];
            $highlightStyleString = $this->buildStyleString($style, $fields, $highlightOverrides);

            // Append the new style line to the header.
            $header .= "Style: {$highlightKey}{$highlightStyleString}\n";
        }
    }
    return $header;
}

/**
 * Builds a style string for the ASS header.
 *
 * @param array $baseStyle
 *   The base style array.
 * @param array $fields
 *   Field definitions from CaptionStyle.
 * @param array $overrides
 *   (optional) Override values for specific fields.
 *
 * @return string
 *   The generated style string for ASS.
 */
private function buildStyleString(array $baseStyle, array $fields, array $overrides = []): string {
    $styleString = '';

    foreach ($fields as $field_name => $metadata) {
        if (!empty($metadata['ass_key'])) {
            $ass_key = $metadata['ass_key'];
            $value = $overrides[$field_name] ?? $baseStyle[$field_name] ?? $metadata['default'];
            $styleString .= ",$value";
        }
    }

    return $styleString;
}

private function generateAssEvents(array $data, array $style): array {
    $assEvents = [];

    switch ($style['type']) {
        case 'sequence':
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
    $highlightCounter = 0;

    foreach ($chunkWords as $index => $wordInfo) {
        $highlightedLine = "";

        if ($applyFade && $index === 0) {
            $highlightedLine .= "{\\fad(500,0)}";
        }

        foreach ($chunkWords as $wordIndex => $otherWordInfo) {
            $otherWord = $otherWordInfo["word"];

            $useSecondaryHighlight = isset($style["secondaryHighlight"]);
            $highlightStyle = $useSecondaryHighlight && $highlightCounter % 5 === 4
                ? "secondaryHighlight"
                : "primaryHighlight";

            $highlightValues = $style[$highlightStyle] ?? [];
            $highlightTag = $this->buildHighlightTag($highlightValues);

            if ($index === $wordIndex) {
                $highlightedLine .= "{\\r$highlightTag}{$otherWord}{\\r}";
            } else {
                $highlightedLine .= " {$otherWord}";
            }

            $highlightCounter++;
        }

        $start = gmdate("H:i:s", floor($wordInfo["start"])) . '.' . sprintf('%02d', ($wordInfo["start"] - floor($wordInfo["start"])) * 100);
        $end = gmdate("H:i:s", floor($wordInfo["end"])) . '.' . sprintf('%02d', ($wordInfo["end"] - floor($wordInfo["end"])) * 100);

        $assEvents[] = "Dialogue: 0,{$start},{$end},Default,,0,0,0,,$highlightedLine";

        $applyFade = false;
    }
}

private function buildHighlightTag(array $highlightValues): string {
    $tag = "";

    if (!empty($highlightValues['colour'])) {
        $tag .= "\\c{$highlightValues['colour']}";
    }
    if (!empty($highlightValues['outline_colour'])) {
        $tag .= "\\3c{$highlightValues['outline_colour']}";
    }
    if (!empty($highlightValues['shadow'])) {
        $tag .= "\\shad{$highlightValues['shadow']}";
    }

    return $tag;
}


}


<?php
/**
 * Generate TV Playlist JSON from TMDB
 * 
 * This script fetches TV series from TMDB and generates a properly formatted
 * tv_playlist.json that works with the player_api.php and IPTV apps.
 * 
 * It generates:
 * - On The Air category
 * - Popular category
 * - Top Rated category
 * - Network-based categories (Netflix, HBO, etc.)
 * - Genre-based categories
 * 
 * Run via GitHub Actions on schedule
 */

$apiKey = getenv('TMDB_API_KEY') ?: getenv('SECRET_API_KEY') ?: '';

if (empty($apiKey)) {
    die("Error: TMDB API key not set. Set TMDB_API_KEY environment variable.\n");
}

// Server URL placeholder - will be replaced when downloaded
$serverUrl = '[[SERVER_URL]]';

$language = 'en-US';
$region = 'US';
$maxPages = 25;

// Output directory
$outputDir = dirname(__DIR__);

echo "=== Generate TV Playlist JSON ===\n\n";

// Initialize output arrays
$outputData = [];
$addedSeriesIds = [];
$num = 0;

// TV Networks with their TMDB IDs
$tvNetworks = [
    "Apple TV+" => 2552,
    "Discovery" => 64,
    "Disney+" => 2739,
    "HBO" => 49,
    "History" => 65,
    "Hulu" => 453,
    "Investigation" => 244,
    "Lifetime" => 34,
    "Netflix" => 213,
    "Oxygen" => 132,
    "Amazon Prime" => 1024,
    "Paramount+" => 4330,
    "Peacock" => 3353
];

/**
 * Fetch data from TMDB API
 */
function fetchTMDB($url) {
    global $apiKey;
    $separator = strpos($url, '?') !== false ? '&' : '?';
    $fullUrl = $url . $separator . 'api_key=' . $apiKey;
    
    $response = @file_get_contents($fullUrl);
    if ($response === false) {
        return null;
    }
    return json_decode($response, true);
}

/**
 * Add series entry to output arrays
 */
function addSeriesEntry($series, $group, $categoryId) {
    global $outputData, $addedSeriesIds, $num, $serverUrl;
    
    $id = $series['id'];
    
    // Skip duplicates
    if (isset($addedSeriesIds[$id])) {
        return false;
    }
    
    // Parse date/year
    $firstAirDate = $series['first_air_date'] ?? '1970-01-01';
    $dateParts = explode("-", $firstAirDate);
    $year = $dateParts[0] ?? '1970';
    $timestamp = strtotime($firstAirDate) ?: 0;
    
    $title = $series['name'] ?? 'Unknown';
    $poster = !empty($series['poster_path']) 
        ? 'https://image.tmdb.org/t/p/original' . $series['poster_path'] 
        : '';
    $backdrop = !empty($series['backdrop_path']) 
        ? 'https://image.tmdb.org/t/p/original' . $series['backdrop_path'] 
        : '';
    $rating = $series['vote_average'] ?? 0;
    $plot = $series['overview'] ?? '';
    
    $seriesData = [
        "num" => ++$num,
        "name" => "{$title} ({$year})",
        "series_id" => $id,
        "cover" => $poster,
        "plot" => $plot,
        "cast" => "",
        "director" => "",
        "genre" => $group,
        "releaseDate" => $firstAirDate,
        "last_modified" => date('Y-m-d H:i:s'),
        "rating" => $rating,
        "rating_5based" => $rating / 2,
        "backdrop_path" => [$backdrop],
        "youtube_trailer" => "",
        "episode_run_time" => "",
        "category_id" => $categoryId,
        "group" => $group
    ];
    
    $outputData[] = $seriesData;
    $addedSeriesIds[$id] = true;
    return true;
}

/**
 * Fetch series from TMDB endpoint
 */
function fetchSeriesFromEndpoint($endpoint, $group, $categoryId, $maxPages = 15, $extraParams = []) {
    global $apiKey, $language, $region;
    
    $baseUrl = "https://api.themoviedb.org/3/{$endpoint}";
    $count = 0;
    
    for ($page = 1; $page <= $maxPages; $page++) {
        $url = "{$baseUrl}?language={$language}&region={$region}&include_adult=false&with_original_language=en&page={$page}";
        
        foreach ($extraParams as $param => $value) {
            $url .= "&" . urlencode($param) . "=" . urlencode($value);
        }
        
        $data = fetchTMDB($url);
        
        if (!$data || empty($data['results'])) {
            break;
        }
        
        foreach ($data['results'] as $series) {
            // Skip series without essential data
            if (empty($series['name']) || empty($series['id'])) {
                continue;
            }
            
            // Skip adult content
            if (!empty($series['adult']) && $series['adult'] === true) {
                continue;
            }
            
            // Skip non-English
            if (!isset($series['original_language']) || $series['original_language'] !== 'en') {
                continue;
            }
            
            // Skip series without poster
            if (empty($series['poster_path'])) {
                continue;
            }
            
            if (addSeriesEntry($series, $group, $categoryId)) {
                $count++;
            }
        }
        
        // Check if we've reached the last page
        $totalPages = $data['total_pages'] ?? 1;
        if ($page >= $totalPages) {
            break;
        }
        
        usleep(100000); // 100ms rate limit
    }
    
    return $count;
}

/**
 * Fetch series by network
 */
function fetchSeriesByNetwork($networkId, $networkName, $categoryId, $maxPages = 10) {
    global $language, $region;
    
    $count = 0;
    
    for ($page = 1; $page <= $maxPages; $page++) {
        $url = "https://api.themoviedb.org/3/discover/tv?language={$language}&region={$region}&include_adult=false&with_original_language=en&with_networks={$networkId}&sort_by=popularity.desc&page={$page}";
        
        $data = fetchTMDB($url);
        
        if (!$data || empty($data['results'])) {
            break;
        }
        
        foreach ($data['results'] as $series) {
            if (empty($series['name']) || empty($series['id'])) {
                continue;
            }
            
            if (!empty($series['adult']) && $series['adult'] === true) {
                continue;
            }
            
            if (!isset($series['original_language']) || $series['original_language'] !== 'en') {
                continue;
            }
            
            if (empty($series['poster_path'])) {
                continue;
            }
            
            if (addSeriesEntry($series, $networkName, $categoryId)) {
                $count++;
            }
        }
        
        $totalPages = $data['total_pages'] ?? 1;
        if ($page >= $totalPages) {
            break;
        }
        
        usleep(100000);
    }
    
    return $count;
}

/**
 * Fetch series by genre
 */
function fetchSeriesByGenre($genreId, $genreName, $maxPages = 25) {
    global $language, $region;
    
    $count = 0;
    
    for ($page = 1; $page <= $maxPages; $page++) {
        $url = "https://api.themoviedb.org/3/discover/tv?language={$language}&region={$region}&include_adult=false&with_original_language=en&with_genres={$genreId}&sort_by=popularity.desc&page={$page}";
        
        $data = fetchTMDB($url);
        
        if (!$data || empty($data['results'])) {
            break;
        }
        
        foreach ($data['results'] as $series) {
            if (empty($series['name']) || empty($series['id'])) {
                continue;
            }
            
            if (!empty($series['adult']) && $series['adult'] === true) {
                continue;
            }
            
            if (!isset($series['original_language']) || $series['original_language'] !== 'en') {
                continue;
            }
            
            if (empty($series['poster_path'])) {
                continue;
            }
            
            if (addSeriesEntry($series, $genreName, (string)$genreId)) {
                $count++;
            }
        }
        
        $totalPages = $data['total_pages'] ?? 1;
        if ($page >= $totalPages) {
            break;
        }
        
        usleep(100000);
    }
    
    return $count;
}

// === FETCH ON THE AIR ===
echo "Fetching On The Air series...\n";
$onAirCount = fetchSeriesFromEndpoint('tv/on_the_air', 'On The Air', '88883', 15);
echo "  Added {$onAirCount} On The Air series\n";

// === FETCH TOP RATED ===
echo "Fetching Top Rated series...\n";
$topRatedCount = fetchSeriesFromEndpoint('tv/top_rated', 'Top Rated', '88882', 15);
echo "  Added {$topRatedCount} Top Rated series\n";

// === FETCH POPULAR ===
echo "Fetching Popular series...\n";
$popularCount = fetchSeriesFromEndpoint('tv/popular', 'Popular', '88881', 15);
echo "  Added {$popularCount} Popular series\n";

// === FETCH BY NETWORK ===
echo "\nFetching series by network...\n";
foreach ($tvNetworks as $networkName => $networkId) {
    $categoryId = "99999" . $networkId;
    echo "Fetching {$networkName} series...\n";
    $networkCount = fetchSeriesByNetwork($networkId, $networkName, $categoryId, 10);
    echo "  Added {$networkCount} {$networkName} series\n";
}

// === FETCH GENRES ===
echo "\nFetching TV genre list...\n";
$genresData = fetchTMDB("https://api.themoviedb.org/3/genre/tv/list?language={$language}");

if ($genresData && !empty($genresData['genres'])) {
    echo "Found " . count($genresData['genres']) . " genres\n";
    
    foreach ($genresData['genres'] as $genre) {
        $genreId = $genre['id'];
        $genreName = $genre['name'];
        
        echo "Fetching {$genreName} series...\n";
        $genreCount = fetchSeriesByGenre($genreId, $genreName, $maxPages);
        echo "  Added {$genreCount} {$genreName} series\n";
    }
} else {
    echo "Warning: Could not fetch TV genres\n";
}

// === SAVE OUTPUT FILES ===
echo "\n=== Saving Output Files ===\n";

// Save tv_playlist.json
$playlistFile = $outputDir . '/tv_playlist.json';
file_put_contents($playlistFile, json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved " . count($outputData) . " series to tv_playlist.json\n";

// === SUMMARY ===
echo "\n=== Summary ===\n";
echo "Total series: " . count($outputData) . "\n";
echo "On The Air: {$onAirCount}\n";
echo "Top Rated: {$topRatedCount}\n";
echo "Popular: {$popularCount}\n";
echo "Unique series IDs: " . count($addedSeriesIds) . "\n";
echo "\nDone!\n";

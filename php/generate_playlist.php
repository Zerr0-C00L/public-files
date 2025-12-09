<?php
/**
 * Generate Playlist JSON from TMDB Lists
 * 
 * This script combines the fetched movie/series lists into a properly formatted
 * playlist.json that works with the player_api.php and IPTV apps.
 * 
 * It generates:
 * - Now Playing category
 * - Popular category  
 * - Genre-based categories
 * 
 * Run via GitHub Actions after fetch_movie_lists.php
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

// Output directories
$movieListsDir = dirname(__DIR__) . '/movie_lists';
$outputDir = dirname(__DIR__);

echo "=== Generate Playlist JSON ===\n\n";

// Initialize output arrays
$outputData = [];
$outputM3u8 = "#EXTM3U\n";
$addedMovieIds = [];
$num = 0;

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
 * Add movie entry to output arrays
 */
function addMovieEntry($movie, $group, $categoryId) {
    global $outputData, $outputM3u8, $addedMovieIds, $num, $serverUrl;
    
    $id = $movie['id'];
    
    // Skip duplicates
    if (isset($addedMovieIds[$id])) {
        return false;
    }
    
    // Parse date/year
    $releaseDate = $movie['release_date'] ?? '1970-01-01';
    $dateParts = explode("-", $releaseDate);
    $year = $dateParts[0] ?? '1970';
    $timestamp = strtotime($releaseDate) ?: 0;
    
    $title = $movie['title'] ?? $movie['name'] ?? 'Unknown';
    $poster = !empty($movie['poster_path']) 
        ? 'https://image.tmdb.org/t/p/original' . $movie['poster_path'] 
        : '';
    $backdrop = !empty($movie['backdrop_path']) 
        ? 'https://image.tmdb.org/t/p/original' . $movie['backdrop_path'] 
        : '';
    $rating = $movie['vote_average'] ?? 0;
    $plot = $movie['overview'] ?? '';
    
    $playUrl = "{$serverUrl}/play.php?movieId={$id}&type=movies";
    
    $movieData = [
        "num" => ++$num,
        "name" => "{$title} ({$year})",
        "stream_type" => "movie",
        "stream_id" => $id,
        "stream_icon" => $poster,
        "rating" => $rating,
        "rating_5based" => $rating / 2,
        "added" => $timestamp,
        "category_id" => $categoryId,
        "container_extension" => "mp4",
        "custom_sid" => null,
        "direct_source" => $playUrl,
        "plot" => $plot,
        "backdrop_path" => $backdrop,
        "group" => $group
    ];
    
    $outputData[] = $movieData;
    $outputM3u8 .= "#EXTINF:-1 group-title=\"{$group}\" tvg-id=\"{$title}\" tvg-logo=\"{$poster}\",{$title} ({$year})\n{$playUrl}\n\n";
    
    $addedMovieIds[$id] = true;
    return true;
}

/**
 * Fetch movies from TMDB endpoint
 */
function fetchMoviesFromEndpoint($endpoint, $group, $categoryId, $maxPages = 15, $extraParams = []) {
    global $apiKey, $language, $region;
    
    $baseUrl = "https://api.themoviedb.org/3/{$endpoint}";
    $count = 0;
    
    for ($page = 1; $page <= $maxPages; $page++) {
        $url = "{$baseUrl}?language={$language}&region={$region}&include_adult=false&with_origin_country=US&page={$page}";
        
        foreach ($extraParams as $param => $value) {
            $url .= "&" . urlencode($param) . "=" . urlencode($value);
        }
        
        $data = fetchTMDB($url);
        
        if (!$data || empty($data['results'])) {
            break;
        }
        
        foreach ($data['results'] as $movie) {
            // Skip movies without essential data
            if (empty($movie['title']) || empty($movie['id'])) {
                continue;
            }
            
            // Skip adult content
            if (!empty($movie['adult']) && $movie['adult'] === true) {
                continue;
            }
            
            // Skip movies without poster
            if (empty($movie['poster_path'])) {
                continue;
            }
            
            if (addMovieEntry($movie, $group, $categoryId)) {
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
 * Fetch movies by genre
 */
function fetchMoviesByGenre($genreId, $genreName, $maxPages = 25) {
    global $apiKey, $language, $region;
    
    $count = 0;
    
    for ($page = 1; $page <= $maxPages; $page++) {
        $url = "https://api.themoviedb.org/3/discover/movie?language={$language}&region={$region}&include_adult=false&with_origin_country=US&with_genres={$genreId}&with_release_type=4|5|6&sort_by=popularity.desc&page={$page}";
        
        $data = fetchTMDB($url);
        
        if (!$data || empty($data['results'])) {
            break;
        }
        
        foreach ($data['results'] as $movie) {
            // Skip movies without essential data
            if (empty($movie['title']) || empty($movie['id'])) {
                continue;
            }
            
            // Skip adult content
            if (!empty($movie['adult']) && $movie['adult'] === true) {
                continue;
            }
            
            // Skip movies without poster
            if (empty($movie['poster_path'])) {
                continue;
            }
            
            if (addMovieEntry($movie, $genreName, (string)$genreId)) {
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

// === FETCH NOW PLAYING ===
echo "Fetching Now Playing movies...\n";
$nowPlayingCount = fetchMoviesFromEndpoint('movie/now_playing', 'Now Playing', '999992', 15, [
    'with_release_type' => '4|5|6'
]);
echo "  Added {$nowPlayingCount} Now Playing movies\n";

// === FETCH POPULAR ===
echo "Fetching Popular movies...\n";
$popularCount = fetchMoviesFromEndpoint('movie/popular', 'Popular', '999991', 15, [
    'with_release_type' => '4|5|6'
]);
echo "  Added {$popularCount} Popular movies\n";

// === FETCH GENRES ===
echo "Fetching genre list...\n";
$genresData = fetchTMDB("https://api.themoviedb.org/3/genre/movie/list?language={$language}");

if ($genresData && !empty($genresData['genres'])) {
    echo "Found " . count($genresData['genres']) . " genres\n";
    
    foreach ($genresData['genres'] as $genre) {
        $genreId = $genre['id'];
        $genreName = $genre['name'];
        
        echo "Fetching {$genreName} movies...\n";
        $genreCount = fetchMoviesByGenre($genreId, $genreName, $maxPages);
        echo "  Added {$genreCount} {$genreName} movies\n";
    }
} else {
    echo "Warning: Could not fetch genres\n";
}

// === SAVE OUTPUT FILES ===
echo "\n=== Saving Output Files ===\n";

// Save playlist.json
$playlistFile = $outputDir . '/playlist.json';
file_put_contents($playlistFile, json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved " . count($outputData) . " movies to playlist.json\n";

// Save playlist.m3u8
$m3u8File = $outputDir . '/playlist.m3u8';
file_put_contents($m3u8File, $outputM3u8);
echo "Saved playlist.m3u8\n";

// === SUMMARY ===
echo "\n=== Summary ===\n";
echo "Total movies: " . count($outputData) . "\n";
echo "Now Playing: {$nowPlayingCount}\n";
echo "Popular: {$popularCount}\n";
echo "Unique movie IDs: " . count($addedMovieIds) . "\n";
echo "\nDone!\n";

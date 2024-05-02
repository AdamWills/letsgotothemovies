<?php

namespace App\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\DomCrawler\Crawler;
use function Termwind\{render};


class Fetch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'movies:today';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gets movies from local theatres.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        render('<div class="text-yellow-300 underline mt-1">Now Playing at Playhouse...</div>');
        $this->table(['Title', 'Time'], $this->getPlayhouseTimes()->toArray());

        render('<div class="text-yellow-300 underline mt-1">Now Playing at Westdale...</div>');
        $this->table(['Title', 'Time'], $this->getWestdaleFilms()->toArray());

        render('<div class="text-yellow-300 underline mt-1">Now Playing at Landmark (Jackson Square)...</div>');
        $this->table(['Title', 'Times'], $this->getLandmarkFilms()->toArray());

        render('<div class="text-yellow-300 underline mt-1">Now Playing at Cineplex Ancaster...</div>');
        $this->table(['Title', 'Times'], $this->getCineplexFilms()->toArray());
    }

    private function getPlayhouseTimes(): Collection
    {
        $html = Cache::remember('playhouse', 60 * 60, function () {
            ray('fetching');
            return file_get_contents('https://playhousecinema.ca/movie-calendar/playhouse');
        });

        $crawler = new Crawler($html);
        return collect($crawler
            ->filter('td.today .view-item')
            ->each(function (Crawler $node, int $index) {
                $time = $node->filter('.date-display-single')->text();
                $title = $node->filter('.view-field a')->text();
                return [
                    'title' => $title,
                    'time' => $time,
                ];
            }));
    }

    private function getWestdaleFilms(): Collection
    {
        $html = Cache::remember('westdale', 60 * 60, function () {
            return file_get_contents('https://www.thewestdale.ca/calendar/');
        });

        $crawler = new Crawler($html);
        return collect($crawler
            ->filter('.tribe-events-calendar-month__day--current .tribe-events-calendar-month__events article')
            ->each(function (Crawler $node, int $index) {
                $time = $node->filter('time')->text();
                $title = $node->filter('h3 a')->text();
                return [
                    'title' => $title,
                    'time' => $time,
                ];
            }));
    }

    private function getLandmarkFilms(): Collection
    {
        $html = Cache::remember('landmark', 60 * 60, function () {
            return file_get_contents('https://www.landmarkcinemas.com/showtimes/hamilton-jackson-square');
        });

        $crawler = new Crawler($html);
        $json = $crawler
            ->filter('script')
            // only include scripts that contain pc.showtimesdata
            ->reduce(function (Crawler $node, $index) {
                return str_contains($node->text(), 'pc.showtimesdata');
            })->eq(0)->text();
        $json = Str::of($json)
            ->replace("var pc = pc || {}; pc.showtimesdata = ", '')
            // replace words that are single quoted with double quotes
            ->replaceMatches('/\'(\w+)\'/', function ($match) {
                return '"' . $match[1] . '"';
            })
            ->replace("};", '}')
            ->value();

        $data = json_decode($json, true);
        $films = collect($data['nowbooking'][0]);


        return $films->map(function ($film) {

            $times = collect($film['Sessions'])
                ->filter(fn($session) => $session['NewDate'] === now()->format('Y-m-d'))
            ->map(fn($session) => collect($session['ExperienceTypes'][0]['Times'])->pluck('StartTime')->join(', '))
            ->first();

            return [
                'title' => $film['Title'],
                'time' => $times,
            ];
        })
            ->filter(fn($movie) => $movie['time'])
            ->sortBy(fn($movie) => $movie['title']);
    }

    private function getCineplexFilms(): Collection
    {
        /* @var array|null $data */
        $data = Cache::remember('cineplex', 60 * 60, function () {
            return Http::withHeaders([
                'ocp-apim-subscription-key' => config('app.api_keys.cineplex'),
            ])
                ->get('https://apis.cineplex.com/prod/cpx/theatrical/api/v1/showtimes', [
                    'language' => 'en',
                    'locationId' => '7415',
                    'date' => now()->format('n/j/Y'),
                ])
                ->json();
        });

        return collect($data[0]['dates'][0]['movies'])
            ->map(function ($movie) {
                $times = collect($movie['experiences'])
                    ->map(function($experience) {
                        $type = $experience['experienceTypes'][0] === 'Regular' ? '' : " (" . $experience['experienceTypes'][0] . ")";

                        return collect($experience['sessions'])
                            ->map(fn($session) => [
                                'time' => Carbon::parse($session['showStartDateTime']),
                                'type' => $type
                                ]);
                    })
                    ->tap(fn($x) => ray($x))
                    ->flatten(1)
                    ->tap(fn($x) => ray($x))
                    ->sortBy(fn($item, $key) => $item['time']->timestamp)
                    ->map(fn($item) => $item['time']->format("H:i") . $item['type'])
                    ->join(', ');

                return [
                    'title' => $movie['name'],
                    'times' => $times,
                ];
            })
            ->sortBy(fn($movie) => $movie['title']);

    }
}

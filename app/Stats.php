<?php namespace BotStats;

use BotStats\Bot;

use Illuminate\Database\Eloquent\Model;

class Stats extends Model {

	/**
	 * Number of miliseconds in a day.
	 * 
	 * @var int
	 */
	const MILISECONDS_IN_A_DAY = 24 * 60 * 60 * 1000;

	/**
	 * Number of miliseconds from 0 a.C to the first moment the UNIX timestamp
	 * represents (01/01/1970 00:00:00).
	 * 
	 * @var int
	 */
	const MILISECONDS_OFFSET = 719528 * 24 * 60 * 60 * 1000;

	/**
	 * List of all current stat types.
	 * 
	 * @var array
	 */
	public static $stats = [
		'active_members',
		'total_members',
		'members_online',
		'guests_online',
		'total_online',
		'total_threads',
		'total_posts',
	];

	/**
	 * Indicates if the model should be timestamped.
	 *
	 * @var bool
	 */
	public $timestamps = false;

	/**
	 * Sets up a one-to-many relationship between Bot and Stats.
	 * @return BotStats\Bot
	 */
	public function bot() {
		return $this->belongsTo('BotStats\Post');
	}

	/**
	 * Gets stats data for a given bot in the format Highcharts expects it.
	 * 
	 * @param  BotStats\Bot
	 * @param  string
	 * @return array
	 */
	public static function getApiData(Bot $bot, $statName)
	{
		if (!in_array($statName, self::$stats)) {
			throw new \InvalidArgumentException("Invalid stat name.");
		}

		// TODO: Find a cleaner way to do this
		$rawSelect = 
			'`' . $statName . '` AS stat, ' .
			'(' .
			'	(' .
			'		(' .
			'			(' .
			'				TO_DAYS(`created_at`) * 24' .
			'			) + HOUR(`created_at`)' .
			'		) * 60 * 60 * 1000' .
			'	) - ' . Stats::MILISECONDS_OFFSET .
			') AS time';

		// Query the database
		$botData = Stats::selectRaw($rawSelect)
		                ->where('bot_id', $bot->id)
		                ->orderBy('created_at')
		                ->get();

		// Transform to a simpler format to be used on the API
		$botData = $botData->transform(function ($stat) {
			return [intval($stat->time), intval($stat->stat)];
		});

		// Hourly data doesn't need any more parsing
		$hourlyData = $botData->toArray();

		// Group data by day
		$botData = $botData->groupBy(function ($stat) {
			return $stat[0] - ($stat[0] % Stats::MILISECONDS_IN_A_DAY);
		});

		// Keep only maximum value
		// TODO: Consider switching to average value
		$botData = $botData->map(function ($items, $key) {
			return array_reduce($items, function ($carry, $item) {
				if ($carry[1] < $item[1]) {
					$carry[1] = $item[1];
				}

				return $carry;
			}, [$key, 0]);
		});

		// Daily data doesn't need any more parsing
		$dailyData = $botData->toArray();

		return [
			[
				'name' => $bot->name,
				'data' => $dailyData,
			],
			[
				'name' => $bot->name,
				'data' => $hourlyData,
			],
		];
	}
}

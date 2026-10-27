//! Nexora routing core — the logic shared between the PHP `RoutingEngine` and the Shopify Functions.
//!
//! This mirrors, line for line, the NEXORA LOGIC in
//! `app/Services/Routing/RoutingEngine.php` (`rankedIds` and `eligibleByLine`):
//!   - `ranked_ids`: location-rule Function output — the compiled ranking reordered by prefer_local.
//!   - `eligible_by_line`: fulfillment-constraints Function output — per line, the locations a
//!     product rule allows (rules only; stock and capacity are not the constraints Function's job).
//!
//! Both are verified against the same `tests/Fixtures/routing/cases.json`, so the simulator (PHP)
//! and real checkout (these Functions) can never silently diverge.

use serde::Deserialize;
use std::collections::BTreeMap;

#[derive(Debug, Deserialize)]
pub struct Constraint {
    pub rule_id: String,
    #[serde(default)]
    pub tags_any: Vec<String>,
    #[serde(default)]
    pub allowed_location_ids: Vec<String>,
}

#[derive(Debug, Deserialize)]
pub struct Config {
    #[serde(default)]
    pub ranking: Vec<String>,
    #[serde(default)]
    pub prefer_local: Option<String>,
    #[serde(default)]
    pub no_split: bool,
    #[serde(default)]
    pub constraints: Vec<Constraint>,
}

#[derive(Debug, Deserialize)]
pub struct Line {
    pub id: String,
    #[serde(default)]
    pub tags: Vec<String>,
}

#[derive(Debug, Deserialize)]
pub struct Location {
    pub id: String,
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub country_code: Option<String>,
    #[serde(default)]
    pub province_code: Option<String>,
}

#[derive(Debug, Deserialize, Default)]
pub struct Destination {
    #[serde(default)]
    pub country_code: Option<String>,
    #[serde(default)]
    pub province_code: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct Scenario {
    #[serde(default)]
    pub lines: Vec<Line>,
    #[serde(default)]
    pub destination: Destination,
    #[serde(default)]
    pub locations: Vec<Location>,
}

/// Location ids, best first. Port of `RoutingEngine::rankedIds`.
pub fn ranked_ids(config: &Config, scenario: &Scenario) -> Vec<String> {
    rank(config, scenario).into_iter().map(|l| l.id.clone()).collect()
}

/// Per line, the locations a product rule allows, in ranked order. Port of `RoutingEngine::eligibleByLine`.
pub fn eligible_by_line(config: &Config, scenario: &Scenario) -> BTreeMap<String, Vec<String>> {
    let ranked = rank(config, scenario);
    let mut out = BTreeMap::new();

    for line in &scenario.lines {
        let allowed = ranked
            .iter()
            .filter(|loc| rule_blocking(config, line, &loc.id).is_none())
            .map(|loc| loc.id.clone())
            .collect();
        out.insert(line.id.clone(), allowed);
    }

    out
}

/// Ordered locations: compiled ranking, then unlisted ones by name, then a stable local-first
/// partition when prefer_local is set. Port of the private `RoutingEngine::rank`.
fn rank<'a>(config: &Config, scenario: &'a Scenario) -> Vec<&'a Location> {
    let mut pool: Vec<&Location> = scenario.locations.iter().collect();
    let mut ordered: Vec<&Location> = Vec::new();

    for id in &config.ranking {
        if let Some(pos) = pool.iter().position(|l| &l.id == id) {
            ordered.push(pool.remove(pos));
        }
    }

    pool.sort_by(|a, b| a.name.cmp(&b.name));
    ordered.extend(pool);

    if let Some(mode) = config.prefer_local.as_deref() {
        let (local, remote): (Vec<&Location>, Vec<&Location>) =
            ordered.into_iter().partition(|l| is_local(mode, l, &scenario.destination));
        ordered = local.into_iter().chain(remote).collect();
    }

    ordered
}

fn is_local(mode: &str, location: &Location, destination: &Destination) -> bool {
    let same_country = match (&destination.country_code, &location.country_code) {
        (Some(d), Some(l)) if !d.is_empty() => d.eq_ignore_ascii_case(l),
        _ => false,
    };

    match mode {
        "country" => same_country,
        "province" => {
            same_country
                && match (&destination.province_code, &location.province_code) {
                    (Some(d), Some(l)) if !d.is_empty() => d.eq_ignore_ascii_case(l),
                    _ => false,
                }
        }
        _ => false,
    }
}

/// The id of the first enabled constraint that forbids this location for this line, if any.
/// Port of the private `RoutingEngine::ruleBlocking` (case-insensitive tag match).
fn rule_blocking<'a>(config: &'a Config, line: &Line, location_id: &str) -> Option<&'a str> {
    let line_tags: Vec<String> = line.tags.iter().map(|t| t.to_lowercase()).collect();

    for constraint in &config.constraints {
        let matches_tag = constraint
            .tags_any
            .iter()
            .any(|t| line_tags.contains(&t.to_lowercase()));

        if !matches_tag {
            continue;
        }

        if !constraint.allowed_location_ids.iter().any(|id| id == location_id) {
            return Some(&constraint.rule_id);
        }
    }

    None
}

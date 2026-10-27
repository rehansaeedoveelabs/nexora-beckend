//! Parity test: the Rust core must produce the same ranking and per-line eligibility as the PHP
//! engine, for the same golden fixtures the PHP suite uses. If the two ever diverge, this fails.

use routing_core::{eligible_by_line, ranked_ids, Config, Scenario};
use serde::Deserialize;
use std::collections::BTreeMap;

#[derive(Deserialize)]
struct Expected {
    ranking: Vec<String>,
    #[serde(default)]
    eligible_by_line: BTreeMap<String, Vec<String>>,
}

#[derive(Deserialize)]
struct Case {
    name: String,
    config: Config,
    scenario: Scenario,
    expected: Expected,
}

#[derive(Deserialize)]
struct Doc {
    cases: Vec<Case>,
}

#[test]
fn matches_frozen_fixtures() {
    // Shared with the PHP suite — one source of truth for both engines.
    let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../tests/Fixtures/routing/cases.json");
    let raw = std::fs::read_to_string(path).expect("read cases.json");
    let doc: Doc = serde_json::from_str(&raw).expect("parse cases.json");

    assert!(doc.cases.len() >= 10, "expected the full fixture set");

    for case in &doc.cases {
        assert_eq!(
            ranked_ids(&case.config, &case.scenario),
            case.expected.ranking,
            "ranking mismatch: {}",
            case.name,
        );
        assert_eq!(
            eligible_by_line(&case.config, &case.scenario),
            case.expected.eligible_by_line,
            "eligible_by_line mismatch: {}",
            case.name,
        );
    }
}

use super::schema;
use super::schema::cart_fulfillment_constraints_generate_run::cart_fulfillment_constraints_generate_run_input::cart::deliverable_lines::Merchandise;
use serde::Deserialize;
use shopify_function::prelude::*;
use shopify_function::Result;
use std::collections::HashMap;

/// Config written by the Nexora backend into the constraint rule's app metafield.
/// Tags are resolved to product IDs server-side, so the Function only matches by product ID.
///   { "no_split": false, "products": { "gid://shopify/Product/1": ["gid://shopify/Location/1"] } }
#[derive(Deserialize, Default)]
struct Config {
    #[serde(skip)]
    invalid: bool,
    #[serde(default)]
    no_split: bool,
    #[serde(default)]
    products: HashMap<String, Vec<String>>,
}

fn parse_config(value: Option<&str>) -> Config {
    value
        .and_then(|value| serde_json::from_str::<Config>(value).ok())
        .unwrap_or(Config {
            invalid: true,
            ..Config::default()
        })
}

fn allowed_locations(config: &Config, product_id: Option<&str>) -> Option<Vec<String>> {
    if config.invalid {
        return Some(Vec::new());
    }

    product_id.and_then(|id| config.products.get(id).cloned())
}

#[shopify_function]
fn cart_fulfillment_constraints_generate_run(
    input: schema::cart_fulfillment_constraints_generate_run::CartFulfillmentConstraintsGenerateRunInput,
) -> Result<schema::CartFulfillmentConstraintsGenerateRunResult> {
    let config = parse_config(
        input
            .fulfillment_constraint_rule()
            .metafield()
            .map(|metafield| metafield.value().as_str()),
    );

    let mut operations = Vec::new();
    let mut all_line_ids = Vec::new();

    for line in input.cart().deliverable_lines() {
        let line_id = line.id().to_string();
        all_line_ids.push(line_id.clone());

        let product_id = match line.merchandise() {
            Merchandise::ProductVariant(variant) => Some(variant.product().id().to_string()),
            _ => None,
        };

        if let Some(locations) = allowed_locations(&config, product_id.as_deref()) {
            operations.push(schema::Operation::DeliverableLinesMustFulfillFromAdd(
                schema::DeliverableLinesMustFulfillFromAddOperation {
                    deliverable_line_ids: Some(vec![line_id.clone()]),
                    location_ids: locations,
                },
            ));
        }
    }

    // "Keep orders together": every deliverable line must ship from the same location.
    if config.no_split && all_line_ids.len() > 1 {
        operations.push(schema::Operation::DeliverableLinesMustFulfillFromSameLocationAdd(
            schema::DeliverableLinesMustFulfillFromSameLocationAddOperation {
                deliverable_line_ids: Some(all_line_ids.clone()),
            },
        ));
    }

    Ok(schema::CartFulfillmentConstraintsGenerateRunResult { operations })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn malformed_or_missing_config_blocks_every_product() {
        for config in [None, Some("not-json")] {
            let parsed = parse_config(config);
            assert_eq!(allowed_locations(&parsed, Some("gid://shopify/Product/1")), Some(vec![]));
        }
    }

    #[test]
    fn an_explicit_empty_product_allow_list_remains_a_blocking_constraint() {
        let parsed = parse_config(Some(
            r#"{"version":1,"no_split":false,"products":{"gid://shopify/Product/1":[]}}"#,
        ));
        assert_eq!(allowed_locations(&parsed, Some("gid://shopify/Product/1")), Some(vec![]));
    }

    #[test]
    fn valid_unconstrained_products_do_not_receive_a_constraint() {
        let parsed = parse_config(Some(r#"{"version":1,"no_split":false,"products":{}}"#));
        assert_eq!(allowed_locations(&parsed, Some("gid://shopify/Product/1")), None);
    }
}

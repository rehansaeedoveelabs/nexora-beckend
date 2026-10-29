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
    #[serde(default)]
    no_split: bool,
    #[serde(default)]
    products: HashMap<String, Vec<String>>,
}

#[shopify_function]
fn cart_fulfillment_constraints_generate_run(
    input: schema::cart_fulfillment_constraints_generate_run::CartFulfillmentConstraintsGenerateRunInput,
) -> Result<schema::CartFulfillmentConstraintsGenerateRunResult> {
    let config: Config = input
        .fulfillment_constraint_rule()
        .metafield()
        .and_then(|m| serde_json::from_str::<Config>(m.value()).ok())
        .unwrap_or_default();

    let mut operations = Vec::new();
    let mut all_line_ids = Vec::new();

    for line in input.cart().deliverable_lines() {
        let line_id = line.id().to_string();
        all_line_ids.push(line_id.clone());

        let product_id = match line.merchandise() {
            Merchandise::ProductVariant(variant) => Some(variant.product().id().to_string()),
            _ => None,
        };

        if let Some(pid) = product_id {
            if let Some(locations) = config.products.get(&pid) {
                if !locations.is_empty() {
                    operations.push(schema::Operation::DeliverableLinesMustFulfillFromAdd(
                        schema::DeliverableLinesMustFulfillFromAddOperation {
                            deliverable_line_ids: Some(vec![line_id.clone()]),
                            location_ids: locations.clone(),
                        },
                    ));
                }
            }
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

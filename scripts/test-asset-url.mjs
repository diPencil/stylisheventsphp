import assert from "node:assert/strict"
import { backendAssetUrl } from "../lib/asset-url.js"

const productionApi = "https://api.nexrobnb.com"

assert.equal(
  backendAssetUrl("/uploads/assets/logo.png", productionApi),
  "https://api.nexrobnb.com/uploads/assets/logo.png",
)
assert.equal(
  backendAssetUrl("https://example.com/logo.png", productionApi),
  "https://example.com/logo.png",
)
assert.equal(backendAssetUrl("/images/static-logo.svg", productionApi), "/images/static-logo.svg")
assert.equal(backendAssetUrl(null, productionApi), "")
assert.equal(backendAssetUrl("", productionApi), "")
assert.equal(backendAssetUrl("data:image/png;base64,abc", productionApi), "data:image/png;base64,abc")
assert.equal(backendAssetUrl("blob:https://nexrobnb.com/abc", productionApi), "blob:https://nexrobnb.com/abc")

console.log("asset-url regression passed")

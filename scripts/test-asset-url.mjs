import assert from "node:assert/strict"
import { backendAssetUrl } from "../lib/asset-url.js"
import { applyAccountRegistrationPrefill } from "../lib/registration-prefill.js"

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

const blankRegistration = {
  fullName: "",
  email: "",
  mobile: "",
  countryCode: "EG",
  countryName: "Egypt",
  city: "",
  nationality: "Egyptian",
}
const account = {
  customer_full_name: "QA Customer",
  email: "qa-customer@example.test",
  phone: "+201000000000",
  customer_address: "QA Address",
  customer_city: "Riyadh",
  customer_specialty: "Cardiology",
  customer_nationality: "Saudi",
  country_code: "SA",
  country_name: "Saudi Arabia",
}

assert.deepEqual(applyAccountRegistrationPrefill(blankRegistration, account), {
  ...blankRegistration,
  fullName: "QA Customer",
  email: "qa-customer@example.test",
  mobile: "+201000000000",
  address: "QA Address",
  city: "Riyadh",
  specialty: "Cardiology",
  nationality: "Saudi",
  countryCode: "SA",
  countryName: "Saudi Arabia",
})

assert.deepEqual(
  applyAccountRegistrationPrefill(
    { ...blankRegistration, fullName: "Typed Name", city: "Typed City", countryCode: "US", countryName: "United States" },
    account,
  ),
  { ...blankRegistration, fullName: "Typed Name", email: "qa-customer@example.test", mobile: "+201000000000", address: "QA Address", city: "Typed City", specialty: "Cardiology", nationality: "Saudi", countryCode: "US", countryName: "United States" },
)

console.log("frontend smoke regressions passed")

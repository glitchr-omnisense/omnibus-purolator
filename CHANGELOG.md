# Changelog

## [Unreleased]

### Fixed
- The shipping document asked for follows the destination and the output: DomesticBillOfLading
  within Canada, InternationalBillOfLading to the United States and abroad, each with its Thermal
  variant for ZPL; it was always DomesticBillOfLadingThermal. The comment claiming GetSlip asks
  again is corrected: the label keeps its `url`.

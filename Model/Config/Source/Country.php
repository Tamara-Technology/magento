<?php

namespace Tamara\Checkout\Model\Config\Source;

class Country extends \Magento\Directory\Model\Config\Source\Country {

    /**
     * @param \Magento\Directory\Model\ResourceModel\Country\Collection $countryCollection
     */
    public function __construct(\Magento\Directory\Model\ResourceModel\Country\Collection $countryCollection, ?string $countryCodes = null)
    {
		parent::__construct($countryCollection);

		if (!empty($countryCodes)) {
			$this->_countryCollection->addCountryCodeFilter(explode(',', $countryCodes), ['iso2']);
		}
    }


}

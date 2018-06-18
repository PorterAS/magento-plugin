<?php
/**
 * @author Convert Team
 * @copyright Copyright (c) 2017 Convert (http://www.convert.no/)
 */
class Convert_Porterbuddy_Model_Timeslots
{
    /**
     * @var Mage_Core_Helper_Data
     */
    protected $coreHelper;

    /**
     * @var Convert_Porterbuddy_Helper_Data
     */
    protected $helper;

    /**
     * @param array|null $data
     * @param Convert_Porterbuddy_Helper_Data|null $helper
     */
    public function __construct(
        array $data = null, // for getModel to work
        Convert_Porterbuddy_Helper_Data $helper = null,
        Mage_Core_Helper_Data $coreHelper = null
    ) {
        $this->helper = $helper ?: Mage::helper('convert_porterbuddy');
        $this->coreHelper = $coreHelper ?: Mage::helper('core');
    }

    /**
     * @param Mage_Sales_Model_Quote_Address $address
     * @return array
     * @throws Exception
     */
    public function getShippingRates(Mage_Sales_Model_Quote_Address $address)
    {
        $address->setLimitCarrier(Convert_Porterbuddy_Model_Carrier::CODE);
        $address->collectShippingRates()->save();

        return $address->getGroupedAllShippingRates();
    }

    /**
     * Returns timeslots grouped by dates for rendering on frontend
     *
     * @param Mage_Sales_Model_Quote_Address $address
     * @param bool $includeDay
     * @return array
     */
    public function getDatesTimeslots(Mage_Sales_Model_Quote_Address $address, $includeDay = true)
    {
        $quote = $address->getQuote();
        $rates = $this->getShippingRates($address);

        $showTimeslots = Convert_Porterbuddy_Model_Carrier::TIMESLOT_CHECKOUT == $this->helper->getTimeslotSelection();

        if (isset($rates[Convert_Porterbuddy_Model_Carrier::CODE])) {
            $timezone = $this->helper->getTimezone();

            /** @var Mage_Sales_Model_Quote_Address_Rate $rate */
            foreach ($rates[Convert_Porterbuddy_Model_Carrier::CODE] as $rate) {
                $methodInfo = $this->helper->parseMethod($rate->getMethod());

                $startTime = new DateTime($methodInfo['start']);
                $startTime->setTimezone($timezone); // shift to local time
                $endTime = new DateTime($methodInfo['end']);
                $endTime->setTimezone($timezone); // shift to local time

                $dateKey = $showTimeslots ? $startTime->format('Y-m-d') : 'delivery-type';

                if (!isset($result[$dateKey])) {
                    if ($showTimeslots) {
                        $dateLabel = $this->coreHelper->formatDate($startTime->format('r'), Mage_Core_Model_Locale::FORMAT_TYPE_FULL);
                        $dateLabel = preg_replace('/\s+\d+$/', '', $dateLabel); // remove year
                        $dateLabel = rtrim($dateLabel, ', ');
                        $result[$dateKey] = array(
                            'label' => $dateLabel,
                            'datetime' => $startTime->format(DateTime::ATOM), // allow client-side formatting
                            'timeslots' => array(),
                        );
                    } else {
                        $result[$dateKey] = array(
                            'label' => $this->helper->__('Select later'),
                            'datetime' => null,
                            'timeslots' => array(),
                        );
                    }
                }

                if (Convert_Porterbuddy_Model_Carrier::METHOD_EXPRESS == $methodInfo['type']) {
                    $result[$dateKey]['timeslots'][$rate->getCode()] = array(
                        'label' => $this->helper->getAsapName(),
                        'value' => $rate->getCode(),
                        'start' => $startTime->format(DateTime::ATOM), // allow client-side formatting
                        'end' => $endTime->format(DateTime::ATOM),
                        'price' => $this->helper->formatPrice($quote, $rate->getPrice()),
                        'return' => $methodInfo['return'],
                        'class' => 'porterbuddy-timeslot-asap' . ($methodInfo['return'] ? ' porterbuddy-timeslot-return' : ''),
                    );
                } elseif (Convert_Porterbuddy_Model_Carrier::METHOD_DELIVERY == $methodInfo['type']) {
                    if ($showTimeslots) {
                        // specific time slot
                        $result[$dateKey]['timeslots'][$rate->getCode()] = array(
                            'label' => $this->formatTimeslot($startTime, $endTime, $includeDay),
                            'value' => $rate->getCode(),
                            'start' => $startTime->format(DateTime::ATOM), // allow client-side formatting
                            'end' => $endTime->format(DateTime::ATOM),
                            'price' => $this->helper->formatPrice($quote, $rate->getPrice()),
                            'return' => $methodInfo['return'],
                            'class' => 'porterbuddy-timeslot-scheduled' . ($methodInfo['return'] ? ' porterbuddy-timeslot-return' : ''),
                        );
                    } else {
                        // generic delivery option, select time slot later
                        $result[$dateKey]['timeslots'][$rate->getCode()] = array(
                            'label' => $this->helper->getChooseLaterName(),
                            'value' => $rate->getCode(),
                            'start' => null,
                            'end' => null,
                            'price' => $this->helper->formatPrice($quote, $rate->getPrice()),
                            'return' => $methodInfo['return'],
                            'class' => 'porterbuddy-timeslot-delivery-select-later' . ($methodInfo['return'] ? ' porterbuddy-timeslot-return' : ''),
                        );
                    }
                }
            }
        }

        $transport = new Varien_Object(['result' => $result]);
        Mage::dispatchEvent('convert_porterbuddy_form_get_dates', array('transport' => $transport));
        $result = $transport->getResult();

        return $result;
    }

    /**
     * Returns open hours range in UTC timezone
     *
     * @param DateTime
     * @return DateTime[]|false Date range or false if not working
     */
    public function getOpenHours(DateTime $baseDate)
    {
        $localTimezone = $this->helper->getTimezone();
        $defaultTimezone = new DateTimeZone('UTC');

        // ensure local timezone
        $baseDate = clone $baseDate;
        $baseDate->setTimezone($localTimezone);

        $openHours = $this->helper->getOpenHours(strtolower($baseDate->format('D')));
        if (false === $openHours) {
            // not working
            return false;
        }

        $openTime = $openHours['open'];
        $closeTime = $openHours['close'];

        // set time in local timezone and convert to UTC
        $openDatetime = clone $baseDate;
        $parts = explode(':', $openTime);
        $openDatetime->setTimezone($localTimezone);
        $openDatetime->setTime($parts[0], $parts[1], 0);
        $openDatetime->setTimezone($defaultTimezone);

        $closeDatetime = clone $baseDate;
        $parts = explode(':', $closeTime);
        $closeDatetime->setTimezone($localTimezone);
        $closeDatetime->setTime($parts[0], $parts[1], 0);
        $closeDatetime->setTimezone($defaultTimezone);

        if ($openDatetime >= $closeDatetime) {
            // misconfig, treat as day off
            return false;
        }

        return array(
            'open' => $openDatetime,
            'close' => $closeDatetime,
        );
    }

    /**
     * Generate pickup windows as large as possible over several days
     *
     * @param DateTime|null $deliveryDate
     * @param int $extraWindows
     * @return array
     */
    public function getAvailabilityPickupWindows()
    {
        // generate up to delivery date + extra windows
        $windows = [];
        $currentTime = $this->helper->getCurrentTime();
        $date = $this->helper->getCurrentTime();

        $addedExtra = 0;
        $triedExtra = 0;
        $extraWindows = $this->helper->getDaysAhead();
        while ($addedExtra < $extraWindows) {
            $hours = $this->getOpenHours($date);
            if ($hours && $currentTime < $hours['close']) {
                $hours['open'] = max($hours['open'], $currentTime);
                $windows[] = array(
                    'start' => $hours['open'],
                    'end' => $hours['close'],
                );
                $addedExtra++;
            }
            $date->modify('+1 day');
            if ($triedExtra++ > 20) {
                // prevent infinite loop in case of misconfigured working hours
                break;
            }
        }

        // add packing time to first window
        $addTime = $this->helper->getPackingTime() + $this->helper->getRefreshOptionsTimeout();
        /** @var DateTime[] $window */
        $window = reset($windows);
        if ($window) {
            $window['start']->modify("+$addTime minutes");
        }

        // convert to API formst
        $windows = array_map(function($window) {
            return array(
                'start' => $this->helper->formatApiDateTime($window['start']),
                'end' => $this->helper->formatApiDateTime($window['end']),
            );
        }, $windows);

        return $windows;
    }

    /**
     * Generate pickup windows as large as possible over several days
     *
     * @param array $methodInfo
     * @return array
     */
    public function getPickupWindows(array $methodInfo)
    {
        // generate up to delivery date + extra windows
        $windows = [];
        $currentTime = $this->helper->getCurrentTime();
        $date = $this->helper->getCurrentTime();

        // can be unknown if method delivery with pickup timeslots later
        if ($methodInfo['start']) {
            $deliveryDate = new DateTime($methodInfo['start']);
            while ($date <= $deliveryDate) {
                $hours = $this->getOpenHours($date); // 09-18
                if ($hours && $currentTime < $hours['close']) {
                    // don't send 9 am when it's already 13
                    $hours['open'] = max($hours['open'], $currentTime);
                    $windows[] = array(
                        'start' => $hours['open'],
                        'end' => $hours['close'],
                    );
                }
                $date->modify('+1 day');
            }
        }

        $addedExtra = 0;
        $triedExtra = 0;
        $extraWindows = $this->helper->getExtraPickupWindows();
        while ($addedExtra < $extraWindows) {
            $hours = $this->getOpenHours($date);
            if ($hours && $currentTime < $hours['close']) {
                $hours['open'] = max($hours['open'], $currentTime);
                $windows[] = array(
                    'start' => $hours['open'],
                    'end' => $hours['close'],
                );
                $addedExtra++;
            }
            $date->modify('+1 day');
            if ($triedExtra++ > 20) {
                // prevent infinite loop in case of misconfigured working hours
                break;
            }
        }

        // add packing time to first window
        $packingTime = $this->helper->getPackingTime();
        /** @var DateTime[] $window */
        $window = reset($windows);
        if ($window) {
            $window['start']->modify("+$packingTime minutes");
        }

        // convert to API formst
        $windows = array_map(function($window) {
            return array(
                'start' => $this->helper->formatApiDateTime($window['start']),
                'end' => $this->helper->formatApiDateTime($window['end']),
            );
        }, $windows);

        return $windows;
    }

    /**
     * Returns date for formula "Want it {date}? Order in the next {N} hours"
     *
     * @return DateTime|false in *local* timezone for convenience
     */
    public function getAvailableUntil()
    {
        $date = $this->helper->getCurrentTime();

        // protect against misconfiguration
        for ($i = 0; $i < 10; $i++) {
            $openHours = $this->getOpenHours($date);

            if ($openHours) {
                // Porterbuddy works until is set in local timezone
                $porterbuddyWorksUntil = clone $openHours['close'];
                $porterbuddyWorksUntil->modify('-' . $this->helper->getPorterbuddyUntil() . ' minutes');

                if ($date < $porterbuddyWorksUntil) {
                    /** @var DateTime $result */
                    $result = $porterbuddyWorksUntil;
                    $result->setTimezone($this->helper->getTimezone());
                    return $result;
                }
            }

            // for future days, we don't need specific opening hour, just make sure it's before closing hour
            $date
                ->modify('+1 day')
                ->setTime(0, 0, 0);
        }

        return false;
    }

    /**
     * Formats timeslot title, e.g. "Friday 10:00 - 12:00" or "Today 14:00 - 16:00"
     *
     * @param DateTime $startTime
     * @param DateTime $endTime
     * @param bool $includeDay
     * @return string
     */
    public function formatTimeslot(DateTime $startTime, DateTime $endTime, $includeDay = true)
    {
        $result = '';

        if ($includeDay) {
            $today = $this->helper->getCurrentTime();
            $tomorrow = clone $today;
            $tomorrow->modify('+1 day');

            if ($startTime->format('Y-m-d') == $today->format('Y-m-d')) {
                $dayOfWeek = $this->helper->__('Today');
            } elseif ($startTime->format('Y-m-d') == $tomorrow->format('Y-m-d')) {
                $dayOfWeek = $this->helper->__('Tomorrow');
            } else {
                $dayOfWeek = $this->helper->__($startTime->format('l'));
            }

            $result = $dayOfWeek . ' ';
        }

        // local time - shift timezone
        $timezone = $this->helper->getTimezone();
        $startTime = clone $startTime;
        $startTime->setTimezone($timezone);
        $endTime = clone $endTime;
        $endTime->setTimezone($timezone);

        $result .= $startTime->format('H:i') . '&ndash;' . $endTime->format('H:i');

        return $result;
    }
}
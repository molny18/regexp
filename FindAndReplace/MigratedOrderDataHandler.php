<?php

declare(strict_types=1);

namespace MultiVitamin\MigrationBundle\DataHandler;

use Doctrine\DBAL\Connection;
use MultiVitamin\CartBundle\Enum\Courier;
use MultiVitamin\CartBundle\Enum\PaymentMode as PaymentModeEnum;
use MultiVitamin\OrderBundle\Enum\OrderWorkflowStatus;
use MultiVitamin\ProductBundle\Entity\Product;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Virgo\Corbis\CartBundle\Enum\AbstractPaymentMode;
use Virgo\Corbis\CartBundle\Model\Address\BillingAddress;
use Virgo\Corbis\CartBundle\Model\Address\BillingAddressInterface;
use Virgo\Corbis\CartBundle\Model\Address\ShippingAddress;
use Virgo\Corbis\CartBundle\Model\Address\ShippingAddressInterface;
use Virgo\Corbis\CartBundle\Model\Cart\BuyableInfo;
use Virgo\Corbis\CartBundle\Model\Cart\PriceDescriptor;
use Virgo\Corbis\CartBundle\Model\Cart\QuantityDescriptor;
use Virgo\Corbis\CartBundle\Model\PaymentMode\Checker\DefaultPaymentModeSupportChecker;
use Virgo\Corbis\CartBundle\Model\PaymentMode\PaymentMode;
use Virgo\Corbis\CartBundle\Model\ShippingMode\CourierShippingMode;
use Virgo\Corbis\CartBundle\Model\ShippingMode\Provider\ShippingModeDataProvider;
use Virgo\Corbis\CartBundle\Model\ShippingMode\RecipientPointShippingMode;
use Virgo\Corbis\CartBundle\Model\ShippingMode\ShippingModeInterface;
use Virgo\Corbis\CustomerBundle\Entity\AbstractAddress;
use Virgo\Corbis\CustomerBundle\Entity\AbstractCustomer;
use Virgo\Corbis\CustomerBundle\Manager\CustomerBillingAddressManager;
use Virgo\Corbis\CustomerBundle\Manager\CustomerShippingAddressManager;
use Virgo\Corbis\OrderBundle\Entity\AbstractOrder;
use Virgo\Corbis\OrderBundle\Entity\Order;
use Virgo\Corbis\OrderBundle\Entity\OrderItem;
use Virgo\Corbis\ProductBundle\Entity\AbstractProduct;
use Virgo\Corbis\ProductBundle\Manager\ProductManager;
use Virgo\Corbis\ProductBundle\Util\ProductShowUrlGenerator;
use Virgo\CoreBundle\Enum\EnumFactory;
use Virgo\CoreBundle\Enum\UrlTypes;
use Virgo\CoreBundle\Exception\NotFoundException;
use Virgo\CoreBundle\Manager\EntityFactoryManager;
use Virgo\CoreBundle\Manager\MetaManager;
use Virgo\CoreBundle\Router\UrlDecorator;
use Virgo\VposBundle\Enum\Currency;

class MigratedOrderDataHandler extends AbstractDataHandler
{
    /*
     * find : \$(?<a>[A-Z]{1})
     * replace : \$\l${a}
     *
     *
     * */


    private const MISSING_SKU = 'missing_sku';
    private const MISSING_USER = 'missing_user';
    private const MISSING_PROD = 'missing_product';
    private const MISSING_ORDER_DATA = 'missing_order_data';
    private const MISSING_ADDRESS = 'missing_address';
    private const MISSING_SHIPPING_MODE = 'missing_shipping_mode';
    private const EMPTY_CART = 'empty_cart';
    private const PAYMENT_MODE_PROBLEM = 'payment_prob';
    private const MISSING_WORKFLOW = 'missing_workflow';
    private const OPEN_STAUTS_ORDERS = 'open_orders';

    /**
     * @var array
     */
    protected $ExtraConnections;

    /**
     * @var EnumFactory
     */
    protected $EnumFactory;

    /**
     * @var TranslatorInterface
     */
    protected $Translator;

    /**
     * @var array
     */
    protected $RecipientPointMap;

    /**
     * @var ProductShowUrlGenerator
     */
    protected $ProductShowUrlGenerator;

    /**
     * @var array
     */
    protected $ErrorDetails = [
        self::MISSING_SKU => [],
        self::MISSING_USER => [],
        self::MISSING_PROD => [],
        self::MISSING_ORDER_DATA => [],
        self::MISSING_ADDRESS => [],
        self::MISSING_SHIPPING_MODE => [],
        self::EMPTY_CART => [],
        self::OPEN_STAUTS_ORDERS => [],
    ];

    protected $OpenStatuses;

    /**
     * @var array
     */
    protected $StatusMap = [
        'P' => OrderWorkflowStatus::PROCESSING,
        'C' => OrderWorkflowStatus::PROCESSING,
        'R' => OrderWorkflowStatus::UNEXPORTABLE_DELETED,
        'S' => OrderWorkflowStatus::SHIPPING,
        'V' => OrderWorkflowStatus::UNEXPORTABLE_DELETED,
        'A' => OrderWorkflowStatus::UNEXPORTABLE_SUCCESS,
        'M' => OrderWorkflowStatus::UNEXPORTABLE_DELETED,
        'H' => OrderWorkflowStatus::UNDER_PURCHASE,
        'I' => OrderWorkflowStatus::RECEIVABLE,
        'B' => OrderWorkflowStatus::RECEIVABLE,
        'K' => OrderWorkflowStatus::PROCESSING,
        'L' => OrderWorkflowStatus::UNEXPORTABLE_DELETED,
        'Y' => OrderWorkflowStatus::UNDER_PURCHASE,
        'Z' => OrderWorkflowStatus::UNDER_PURCHASE,
        'D' => OrderWorkflowStatus::RECEIVABLE,
        'E' => OrderWorkflowStatus::UNDER_PURCHASE,
        'F' => OrderWorkflowStatus::RECEIVABLE,
        'G' => OrderWorkflowStatus::UNDER_PURCHASE,
        'J' => OrderWorkflowStatus::RECEIVABLE,
        'N' => OrderWorkflowStatus::UNDER_PURCHASE,
        'U' => OrderWorkflowStatus::PROCESSING,
        'O' => OrderWorkflowStatus::UNEXPORTABLE_SUCCESS,
        'T' => OrderWorkflowStatus::UNEXPORTABLE_SUCCESS,
        'Q' => OrderWorkflowStatus::UNEXPORTABLE_DELETED,
    ];

    public function __construct(MetaManager $Manager, Connection $MigrationConnection, Connection $DoctrineConnection, LoggerInterface $Logger, string $FilePath, TokenInterface $Token, array $ExtraConnections, EnumFactory $EnumFactory, TranslatorInterface $Translator, array $RecipientPointMap, ProductShowUrlGenerator $ProductShowUrlGenerator)
    {
        parent::__construct($Manager, $MigrationConnection, $DoctrineConnection, $Logger, $FilePath, $Token);
        $This->extraConnections = $ExtraConnections;
        $This->enumFactory = $EnumFactory;
        $This->translator = $Translator;
        $This->recipientPointMap = $RecipientPointMap;
        $This->productShowUrlGenerator = $ProductShowUrlGenerator;
        $This->openStatuses = array_filter($This->statusMap, function ($Item) {
            if ($Item === OrderWorkflowStatus::UNEXPORTABLE_DELETED || $Item === OrderWorkflowStatus::UNEXPORTABLE_SUCCESS) {
                return false;
            }

            return true;
        });
    }

    protected function countErrors(string $Type, string $JoomlaOrderId): void
    {
        $This->errorDetails[$Type][] = $JoomlaOrderId;
    }

    private function getUserConnection(): Connection
    {
        return $This->extraConnections['user'];
    }

    private function getProductConnection(): Connection
    {
        return $This->extraConnections['product'];
    }

    private function getCustomerManager(): MetaManager
    {
        return $This->manager->getCustomerManager();
    }

    private function getOrderBasicData(int $Id): array
    {
        $State = $This->migrationConnection->prepare('SELECT * FROM jos_vm_orders WHERE order_id=:orderid');
        $State->bindValue(':orderid', $Id, PDO::PARAM_INT);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Result = $State->fetch();
        if (false === $Result) {
            throw new NotFoundException('Order data not found');
        }

        return $Result;
    }

    private function getOwnId(string $JoomlaId): ?string
    {
        $State = $This->doctrineConnection->prepare('SELECT id FROM order_id_map WHERE joomla_id=:joomlaid');
        $State->bindValue(':joomlaid', $JoomlaId, PDO::PARAM_INT);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Res = $State->fetch();
        $Id = false === $Res ? null : $Res['id'];

        return $Id;
    }

    private function getMigrationDate(string $JoomlaId): ?string
    {
        $State = $This->doctrineConnection->prepare('SELECT cdate FROM order_id_map WHERE joomla_id=:joomlaid');
        $State->bindValue(':joomlaid', $JoomlaId, PDO::PARAM_INT);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Res = $State->fetch();
        $DateStamp = false === $Res ? null : $Res['cdate'];

        return $DateStamp;
    }

    private function createOrderIdMap(int $OwnId, string $JoomlaId): void
    {
        $Date = new \DateTime('now');
        $Date = $Date->getTimestamp();
        $State = $This->doctrineConnection->prepare('INSERT INTO order_id_map VALUES (:ownid,:joomlaid,:cdate)');
        $State->bindValue(':ownid', $OwnId, PDO::PARAM_INT);
        $State->bindValue(':joomlaid', $JoomlaId, PDO::PARAM_INT);
        $State->bindValue(':cdate', $Date, PDO::PARAM_INT);
        $State->execute();
    }

    private function updateOrderIdMap(string $Id): void
    {
        $Date = new \DateTime('now');
        $Date = $Date->getTimestamp();
        $State = $This->doctrineConnection->prepare('UPDATE order_id_map SET cdate=:cdate WHERE id=:id');
        $State->bindValue(':cdate', $Date, PDO::PARAM_INT);
        $State->bindValue(':id', $Id, PDO::PARAM_INT);
        $State->execute();
    }

    private function handleOrderMap(string $JoomlaId, int $Id): void
    {
        $OwnId = $This->getOwnId($JoomlaId);
        if (null === $OwnId) {
            $This->createOrderIdMap($Id, $JoomlaId);
        } else {
            $This->updateOrderIdMap((string) $Id);
        }
    }

    private function getOwnOwner($Id): AbstractCustomer
    {
        $State = $This->getUserConnection()->prepare('SELECT id FROM migration_id_map WHERE migration_id=:migrationid');
        $State->bindValue(':migrationid', $Id);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $OwnId = $State->fetch()['id'];
        $Manager = $This->getCustomerManager();

        return $Manager->findById($OwnId, $This->token);
    }

    private function getBillingAddress(string $UserInfoId): AbstractAddress
    {
        /** @var $Manager CustomerBillingAddressManager */
        $Manager = $This->getCustomerManager()->getBillingAddressManager();
        $Conn = $This->getUserConnection();
        $State = $Conn->prepare('SELECT billing_address_id FROM migration_addresses_id_map WHERE user_info_id=:userinfoid');
        $State->bindValue(':userinfoid', $UserInfoId, PDO::PARAM_STR);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $AddressId = $State->fetch();

        return $Manager->findById($AddressId['billing_address_id'], $This->token);
    }

    private function getAddress(string $OrderId, string $Type): array
    {
        $State = $This->migrationConnection->prepare('SELECT * FROM jos_vm_order_user_info WHERE order_id=:orderid AND address_type=:type');
        $State->bindValue(':orderid', $OrderId, PDO::PARAM_INT);
        $State->bindValue(':type', $Type, PDO::PARAM_STR);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Result = $State->fetch();
        if (false === $Result) {
            throw new NotFoundException();
        }

        return $Result;
    }

    private function getShippingAddress(string $UserInfoId): AbstractAddress
    {
        /** @var $Manager CustomerBillingAddressManager */
        $Manager = $This->getCustomerManager()->getShippingAddressManager();
        $Conn = $This->getUserConnection();
        $State = $Conn->prepare('SELECT shipping_address_id FROM migration_addresses_id_map WHERE user_info_id=:userinfoid');
        $State->bindValue(':userinfoid', $UserInfoId, PDO::PARAM_STR);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $AddressId = $State->fetch();

        return $Manager->findById($AddressId['shipping_address_id'], $This->token);
    }

    private function searchShippingAddress(string $OrderId): AbstractAddress
    {
        $Data = $This->getAddress($OrderId, 'ST');
        $State = $This->migrationConnection->prepare('SELECT user_info_id FROM jos_vm_user_info WHERE user_id=:userid AND address_type="ST" AND zip=:zip AND address_1=:address');
        $State->bindValue(':userid', $Data['user_id'], PDO::PARAM_INT);
        $State->bindValue(':zip', $Data['zip'], PDO::PARAM_INT);
        $State->bindValue(':address', $Data['address_1'], PDO::PARAM_STR);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Id = $State->fetch();
        $State = $This->getUserConnection()->prepare('SELECT shipping_address_id FROM migration_addresses_id_map WHERE user_info_id=:userinfoid');
        $State->bindValue(':userinfoid', $Id['user_info_id'], PDO::PARAM_INT);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Id = $State->fetch();
        /** @var $Manager CustomerShippingAddressManager */
        $Manager = $This->getCustomerManager()->getShippingAddressManager();

        return $Manager->findById($Id['shipping_address_id'], $This->token);
    }

    private function searchBillingAddress(string $OrderId): AbstractAddress
    {
        $Data = $This->getAddress($OrderId, 'BT');
        $State = $This->migrationConnection->prepare('SELECT user_info_id FROM jos_vm_user_info WHERE user_id=:userid AND address_type="BT" AND zip=:zip AND address_1=:address');
        $State->bindValue(':userid', $Data['user_id'], PDO::PARAM_INT);
        $State->bindValue(':zip', $Data['zip'], PDO::PARAM_INT);
        $State->bindValue(':address', $Data['address_1'], PDO::PARAM_STR);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Id = $State->fetch();
        $State = $This->getUserConnection()->prepare('SELECT billing_address_id FROM migration_addresses_id_map WHERE user_info_id=:userinfoid');
        $State->bindValue(':userinfoid', $Id['user_info_id'], PDO::PARAM_INT);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Id = $State->fetch();
        /** @var $Manager CustomerBillingAddressManager */
        $Manager = $This->getCustomerManager()->getBillingAddressManager();

        return $Manager->findById($Id['billing_address_id'], $This->token);
    }

    private function getUserInfoType(string $UserInfoId): string
    {
        $State = $This->migrationConnection->prepare('SELECT address_type FROM jos_vm_user_info WHERE user_info_id=:uiid');
        $State->bindValue(':uiid', $UserInfoId);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);

        return $State->fetch()['address_type'] ?? 'BT';
    }

    private function getOrderProducts(string $OrderId): array
    {
        $State = $This->migrationConnection->prepare('SELECT product_id,product_quantity,product_item_price FROM jos_vm_order_item WHERE order_id=:orderid');
        $State->bindValue(':orderid', $OrderId, PDO::PARAM_INT);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);

        return $State->fetchAll();
    }

    private function getSku(string $ProductId): ?string
    {
        $State = $This->migrationConnection->prepare('SELECT product_sku FROM jos_vm_product WHERE product_id=:productid');
        $State->bindValue(':productid', $ProductId, PDO::PARAM_INT);
        $State->execute();
        $State->setFetchMode(PDO::FETCH_ASSOC);
        $Res = $State->fetch();

        return $Res['product_sku'];
    }

    private function getProduct(string $Sku): AbstractProduct
    {
        /** @var $Manager ProductManager */
        $Manager = $This->manager->getProductManager();

        return $Manager->findBySku($Sku, $This->token);
    }

    private function createProductImageReferences(AbstractProduct $Product, EntityFactoryManager $Manager, ?TokenInterface $Token): array
    {
        $ImageReferences = [];
        foreach ($Product->getImagesPrimaryFirst($Token) as $Image) {
            $ImageReferences[] = $Manager->createEntityReference($Image);
        }
        $PrimaryCategoryImage = $Product->getPrimaryCategory($Token)->getDefaultImage($Token);
        if (empty($ImageReferences) && $PrimaryCategoryImage) {
            $ImageReferences[] = $Manager->createEntityReference($PrimaryCategoryImage);
        }

        return $ImageReferences;
    }

    private function createBuyableInfo(AbstractProduct $Product): BuyableInfo
    {
        $Manager = $This->manager->getFactoryManager();

        return new BuyableInfo(
            $Manager->createEntityReference($Product),
            $Product->getDisplayName(),
            $This->createProductImageReferences($Product, $Manager, $This->token),
            $This->createUrlDecorator($Product, $This->token),
            $Product->getSku(),
            $This->guessManufacturer($Product)
        );
    }

    private function createUrlDecorator(AbstractProduct $Product, ?TokenInterface $Token): UrlDecorator
    {
        return $This->productShowUrlGenerator->generateShowUrlList(
            $Product, ['urlType' => 'all'],
            $Token,
            UrlTypes::create(UrlTypes::RELATIVE)
        );
    }

    private function createOrderItems(string $OrderId, array $OrderBasicdata): array
    {
        $JoomlaOrderProducts = $This->getOrderProducts($OrderId);
        $OrderItems = [];
        $Tmp = $This->fileExportPath;
        foreach ($JoomlaOrderProducts as $JoomlaOrderProduct) {
            $Sku = $This->getSku($JoomlaOrderProduct['product_id']);
            if (null === $Sku || '' === $Sku || false === $Sku) {
                $This->countErrors(self::MISSING_SKU, $JoomlaOrderProduct['product_id']);
                $This->logOrdersInProcess($OrderBasicdata, self::MISSING_SKU);
                throw new NotFoundException($JoomlaOrderProduct['product_id']);
            }
            try {
                $Product = $This->getProduct((string) $Sku);
            } catch (NotFoundException $Ex) {
                $This->countErrors(self::MISSING_PROD, $Sku);
                $This->logOrdersInProcess($OrderBasicdata, self::MISSING_PROD);
                throw $Ex;
            }

            $Item = new OrderItem();
            $Item->setBuyableInfo($This->createBuyableInfo($Product));
            $Item->setPrice($This->makePrice((int) round((float) $JoomlaOrderProduct['product_item_price'])));
            $Item->setQuantity(new QuantityDescriptor((float) $JoomlaOrderProduct['product_quantity'], 'db'));
            $OrderItems[] = $Item;
        }
        if (empty($OrderItems)) {
            $This->countErrors(self::EMPTY_CART, $OrderId);
            $This->logOrdersInProcess($OrderBasicdata, self::EMPTY_CART);
            throw  new \Exception('Empty Cart');
        }

        return $OrderItems;
    }

    private function logOrdersInProcess(array $OrderData, string $ErrorType): void
    {
        if (array_key_exists($OrderData['order_status'], $This->openStatuses)) {
            $This->errorDetails[self::OPEN_STAUTS_ORDERS][$ErrorType][] = $OrderData['order_id'];
        }
    }

    private function makePrice(float $TotalNetPrice, $Vat = 27.0): PriceDescriptor
    {
        return new PriceDescriptor($TotalNetPrice, $This->enumFactory->create(Currency::class, Currency::HUF), $Vat);
    }

    private function guessManufacturer(Product $Product): ?string
    {
        $State = $This->getProductConnection()->prepare('SELECT name FROM manufacturer WHERE id = :id');
        $State->bindValue(':id', $Product->getManufacturerRef()->getId());
        $State->execute();
        $Result = $State->fetch(PDO::FETCH_ASSOC);

        return false !== $Result ? $Result['name'] : null;
    }

    private function createShippingAddress(AbstractAddress $Address): ShippingAddressInterface
    {
        $Manager = $This->manager->getFactoryManager();

        return new ShippingAddress($Manager->createEntityReference($Address), $Manager->createEntityReference($Address->getOwner($This->token)), $Address->getCountry(), $Address->getCity(),
            $Address->getZipCode(), $Address->getAddress(), $Address->getEmail(), $Address->getName(), $Address->getPhone());
    }

    private function shippingMethodSplitter(string $ShippingMethodId): array
    {
        $Exploded = explode('|', $ShippingMethodId);
        if (count($Exploded) < 5) {
            return [
                'mode' => $Exploded[0],
                'shipping_cost' => (int) $Exploded[3],
            ];
        }

        return [
            'mode' => $Exploded[0],
            'shop_id' => (int) $Exploded[4],
        ];
    }

    private function validateComment(string $Comment): string
    {
        $Comment = mb_convert_encoding($Comment, 'UTF-8');

        return 254 < mb_strlen($Comment) ? mb_substr($Comment, 0, 253) : $Comment;
    }

    private function handleShippingModeAddresses(array $Data): array
    {
        $Type = $This->getUserInfoType($Data['user_info_id']);
        if ('BT' === $Type) {
            $BillingAddress = $This->getBillingAddress($Data['user_info_id']);
            try {
                $ShippingAddress = $This->searchShippingAddress($Data['order_id']);
            } catch (NotFoundException $Ex) {
                $ShippingAddress = $BillingAddress;
            }
        } elseif ('ST' === $Type) {
            $ShippingAddress = $This->getShippingAddress($Data['user_info_id']);
            try {
                $BillingAddress = $This->searchBillingAddress($Data['order_id']);
            } catch (NotFoundException $Ex) {
                $BillingAddress = $ShippingAddress;
            }
        }

        return [
            'SA' => $This->createShippingAddress($ShippingAddress),
            'BA' => $This->createBillingAddress($BillingAddress),
        ];
    }

    private function createBillingAddress(AbstractAddress $Address): BillingAddressInterface
    {
        /** @var $Manager EntityFactoryManager */
        $Manager = $This->manager->getFactoryManager();

        return new BillingAddress($Manager->createEntityReference($Address), $Manager->createEntityReference($Address->getOwner($This->token)), $Address->getCountry(), $Address->getCity(),
            $Address->getZipCode(), $Address->getAddress(), $Address->getEmail(), $Address->getName(), $Address->getPhone(), null, null, null);
    }

    private function createPersonalShippingMode(int $JoomlaShopId): ShippingModeInterface
    {
        $Model = new RecipientPointShippingMode($This->translator->trans(
            ShippingModeDataProvider::TRANS_KEY_PREFIX.ShippingModeDataProvider::RECIPIENT_POINT));
        $Model->applyConfigFormData(['id' => $This->recipientPointMap[$JoomlaShopId]]);

        return $Model;
    }

    private function createCourierShippingMode(int $ShippingCost): ShippingModeInterface
    {
        $Model = new CourierShippingMode($This->translator->trans(ShippingModeDataProvider::TRANS_KEY_PREFIX.ShippingModeDataProvider::COURIER), $This->makePrice($ShippingCost, 0.0));
        $Model->applyConfigFormData(['id' => Courier::DEALER24]);

        return $Model;
    }

    private function createShippingMode(array $Data): ShippingModeInterface
    {
        if ('standard_shipping' === $Data['mode']) {
            return $This->createPersonalShippingMode($Data['shop_id']);
        } else {
            return $This->createCourierShippingMode($Data['shipping_cost']);
        }
    }

    private function createPaymentMode(string $Id, array $OrderBasicdata): PaymentMode
    {
        try {
            /* @var AbstractPaymentMode $Enum*/
            $Enum = $This->enumFactory->create(PaymentModeEnum::class, PaymentModeEnum::CASH_ON_DELIVERY);
        } catch (\InvalidArgumentException $Ex) {
            $This->countErrors(self::PAYMENT_MODE_PROBLEM, $Id);
            $This->logOrdersInProcess($OrderBasicdata, self::PAYMENT_MODE_PROBLEM);
        }

        return new PaymentMode($Enum, new DefaultPaymentModeSupportChecker());
    }

    private function handleShippingModeCreation(AbstractOrder $Order, array $Data): AbstractOrder
    {
        $ShippingMethodId = $This->shippingMethodSplitter($Data['ship_method_id']);
        $Mode = $This->createShippingMode($ShippingMethodId);
        if ($Mode instanceof CourierShippingMode) {
            $Addresses = $This->handleShippingModeAddresses($Data);
            $Order->setShippingAddress($Addresses['SA']);
            $Order->setBillingAddress($Addresses['BA']);
        } else {
            $Addresses = $This->handleShippingModeAddresses($Data);
            $Order->setBillingAddress($Addresses['BA']);
        }

        $Order->setShippingMode($Mode);

        return $Order;
    }

    private function flushEntityManager(int $Loop): void
    {
        if ($Loop % 50 === 0) {
            $This->manager->flushEntityManagerInternalCache();
            $This->getCustomerManager()->flushEntityManagerInternalCache();
            $This->manager->getProductManager()->flushEntityManagerInternalCache();
        }
    }

    public function migrate(array $Ids, OutputInterface $Output)
    {
        $This->createErrorLogs();
        $Loop = 0;
        $Bar = new ProgressBar($Output, count($Ids));
        $Bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $Bar->setBarCharacter('<fg=yellow>=</>');
        $Bar->setProgressCharacter("\xF0\x9F\x8D\xBA");
        $Bar->start();
        $Portal = $This->manager->getPortalManager()->findbyId(1, $This->token);
        foreach ($Ids as $Id) {
            if (empty($Id)) {
                continue;
            }
            $MigrationDate = $This->getMigrationDate($Id);
            try {
                $OrderBasicData = $This->getOrderBasicData((int) $Id);
            } catch (NotFoundException $Ex) {
                $This->countErrors(self::MISSING_ORDER_DATA, $Id);
                $This->logOrdersInProcess(['order_status' => 'T'], self::MISSING_ORDER_DATA);
                $Bar->advance();
                continue;
            }
            if (null !== $MigrationDate) {
                if ((int) $OrderBasicData['mdate'] < $MigrationDate) {
                    $Bar->advance();
                    continue;
                }
            }
            if (empty($OrderBasicData['ship_method_id']) || 'X' === $OrderBasicData['order_status'] || 0 === $OrderBasicData['order_total']) {
                if (empty($OrderBasicData['ship_method_id'])) {
                    $This->countErrors(self::MISSING_SHIPPING_MODE, $Id);
                    $This->logOrdersInProcess($OrderBasicData, self::MISSING_SHIPPING_MODE);
                } elseif (0 === $OrderBasicData['order_total']) {
                    $This->countErrors(self::MISSING_ORDER_DATA, $Id);
                    $This->logOrdersInProcess($OrderBasicData, self::MISSING_ORDER_DATA);
                }
                $Bar->advance();
                continue;
            }
            /* @var $Order AbstractOrder */
            $Order = null === $MigrationDate ? $This->manager->createNew() : $Order = $This->manager->findById($This->getOwnId($Id), $This->token);
            $IsNew = $Order->isNew();

            try {
                $Order->setOwner($This->getOwnOwner($OrderBasicData['user_id']));
            } catch (NotFoundException $Ex) {
                $This->countErrors(self::MISSING_USER, $OrderBasicData['user_id']);
                $This->logOrdersInProcess($OrderBasicData, self::MISSING_USER);
                $This->logger->alert('Order migration failed, User Not Found. OrderId: '.$Id);
                $Bar->advance();
                continue;
            }
            try {
                $Order = $This->handleShippingModeCreation($Order, $OrderBasicData);
            } catch (NotFoundException $Ex) {
                $This->countErrors(self::MISSING_ADDRESS, $Id);
                $This->logOrdersInProcess($OrderBasicData, self::MISSING_ADDRESS);
                $This->logger->alert('Order migration failed, Address Not Found. OrderId: '.$Id);
                $Bar->advance();
                continue;
            }
            try {
                $Order->setItems($This->createOrderItems($Id, $OrderBasicData));
            } catch (\Exception $Ex) {
                $This->logger->alert('Order migration failed, Product Not Found. ProductSku: '.$Ex->getMessage());
                $Bar->advance();
                continue;
            }

            $Order->setPaymentMode($This->createPaymentMode((string) $Id, $OrderBasicData));
            $Order->setRemoteDirty(true);
            $Order->setComment($This->validateComment($OrderBasicData['customer_note']));
            $Order->setTotalPrice($This->makePrice((int) $OrderBasicData['order_subtotal']));
            try {
                /* @var $Workflow OrderWorkflowStatus*/
                $Workflow = $This->manager->getEnumFactory()->create(OrderWorkflowStatus::class, $This->statusMap[$OrderBasicData['order_status']]);
            } catch (\InvalidArgumentException $Ex) {
                $This->countErrors(self::MISSING_WORKFLOW, $Id);
                $Bar->advance();
                continue;
            }
            $Order->setWorkflowStatus($Workflow);

            if ($IsNew) {
                $Order->setPortal($Portal);
            }
            $CreatedDate = (new \DateTime())->setTimestamp((int) $OrderBasicData['cdate']);
            $Order->migration_created_date = $CreatedDate;
            $This->manager->save($Order, $This->token);

            if ($IsNew) {
                $This->createOrderIdMap($Order->getId(), $Id);
            } else {
                $This->updateOrderIdMap($Order->getId());
            }
            $This->flushEntityManager($Loop);
            ++$Loop;
            $Bar->advance();
        }
        $This->createErrorLogs();
        $Bar->finish();
    }

    private function createErrorLogs()
    {
        foreach ($This->errorDetails as $Type => $Ids) {
            if ($Type === self::OPEN_STAUTS_ORDERS) {
                $This->logOpenErrors($Ids);
                continue;
            }
            $Path = $This->fileExportPath.'/'.$Type.'.txt';
            foreach ($Ids as $Id) {
                file_put_contents($Path, $Id.PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        }
    }

    private function logOpenErrors(array $Items)
    {
        foreach ($Items as $Type => $Ids) {
            $Path = $This->fileExportPath.'/'.$Type.'_open.txt';
            foreach ($Ids as $Id) {
                file_put_contents($Path, $Id.PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        }
    }
}

<?php

namespace Netgen\Bundle\EzSyliusBundle\Core\FieldType\SyliusProduct;

use Doctrine\ORM\EntityManager;
use Gedmo\Sluggable\SluggableListener;
use eZ\Publish\SPI\FieldType\FieldStorage;
use eZ\Publish\SPI\Persistence\Content\VersionInfo;
use eZ\Publish\SPI\Persistence\Content\Field;
use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\Core\MVC\Symfony\Locale\LocaleConverterInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Core\Model\ProductTranslation;

class SyliusProductStorage implements FieldStorage
{
    protected $repository;
    protected $manager;
    protected $sluggable_listener;
    protected $contentService;
    protected $taxRepository;
    protected $localeConverter;

    public function __construct(
        RepositoryInterface $syliusProductRepository,
        EntityManager $syliusManager,
        SluggableListener $sluggableListener,
        ContentService $contentService,
        RepositoryInterface $taxRepository,
        LocaleConverterInterface $localeConverter
    )
    {
        $this->repository = $syliusProductRepository;
        $this->manager = $syliusManager;
        $this->sluggable_listener = $sluggableListener;
        $this->contentService = $contentService;
        $this->taxRepository = $taxRepository;
        $this->localeConverter = $localeConverter;
    }

    /**
     * Stores value for $field in an external data source.
     *
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @param array $context
     *
     * @return true Indicating internal value data has changed
     */
    public function storeFieldData( VersionInfo $versionInfo, Field $field, array $context )
    {
        $data = $field->value->externalData;

        $name = $data['name'];
        $price = $data['price'];
        $price *= 100; // sylius feature
        $desc = $data['description'];
        $slug = $data['slug'];
        $available_on = $data['available_on'];
        $weight = $data['weight'];
        $height = $data['height'];
        $width = $data['width'];
        $depth = $data['depth'];
        $sku = $data['sku'];
        $tax_category = $data['tax_category'];

        $POSIXLocale = $this->localeConverter->convertToPOSIX( $field->languageCode );

        //check if sylius product already exists
        $product = $this->repository->find( $field->value->data['sylius_id'] );

        if ( !$product )
        {
            $product = $this->repository->createNew();
        }

        $translation = new ProductTranslation();
        $translation->setLocale( $POSIXLocale );

        if ( !$product->hasTranslation( $translation ) )
        {
            $product->addTranslation( $translation );
        }

        /** @var \Sylius\Component\Core\Model\Product $product */
        $product
            ->setCurrentLocale( $POSIXLocale )
            ->setName( $name )
            ->setDescription( $desc )
            ->setPrice( (int)$price );

        if ( $slug )
        {
            $product->setSlug( $slug );
        }

        if ( $available_on )
        {
            if ( !$available_on instanceof \DateTime )
            {
                $available_on = new \DateTime( $available_on );
            }

            $product->setAvailableOn( $available_on );
        }

        // set tax category
        if ( $tax_category != '0' && !empty( $tax_category ) )
        {
            /** @var \Sylius\Component\Taxation\Model\TaxCategoryInterface $tax_category */
            $tax_category = $this->taxRepository->findOneBy( array( 'name' => $tax_category ) );
            $product->setTaxCategory( $tax_category );
        }

        // set additional info
        /** @var \Sylius\Component\Core\Model\ProductVariant $master_variant */
        $master_variant = $product->getMasterVariant();
        $master_variant->setWeight( $weight )
            ->setHeight( $height )
            ->setWidth( $width )
            ->setDepth( $depth )
            ->setSku( $sku );

        // custom transliterator
        $this->sluggable_listener->setTransliterator( array( 'Netgen\Bundle\EzSyliusBundle\Util\Urlizer', 'transliterate' ) );
        $this->sluggable_listener->setUrlizer( array( 'Netgen\Bundle\EzSyliusBundle\Util\Urlizer', 'urlize' ) );

        $this->manager->persist( $product );
        $this->manager->flush();

        // fetch product again to get id
        $productId = $product->getId();
        $field->value->data['sylius_id'] = $productId;

        return true;
    }

    /**
     * Populates $field value property based on the external data.
     *
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @param array $context
     *
     * @return void
     */
    public function getFieldData( VersionInfo $versionInfo, Field $field, array $context )
    {
        /** @var \Sylius\Component\Core\Model\Product $product */
        $product = $this->repository->find( $field->value->data['sylius_id'] );

        if ( !empty( $product ) )
        {
            $product->setCurrentLocale(
                $this->localeConverter->convertToPOSIX(
                    $field->languageCode
                )
            );

            $name = $product->getName();
            $price = $product->getPrice();
            $price /= 100; // sylius feature
            $description = $product->getDescription();
            $slug = $product->getSlug();

            /** @var \DateTime $available_on */
            $available_on = $product->getAvailableOn();
            $available_on = $available_on->format( 'Y-m-d H:i' );

            $tax_category = "";
            if ( $product->getTaxCategory() )
            {
                $tax_category = $product->getTaxCategory()->getName();
            }

            /** @var \Sylius\Component\Core\Model\ProductVariant $master_variant */
            $master_variant = $product->getMasterVariant();
            $weight = $master_variant->getWeight();
            $height = $master_variant->getHeight();
            $width = $master_variant->getWidth();
            $depth = $master_variant->getDepth();
            $sku = $master_variant->getSku();

            $field->value->externalData = array(
                'name' => $name,
                'price' => $price,
                'description' => $description,
                'slug' => $slug,
                'available_on' => $available_on,
                'weight' => $weight,
                'height' => $height,
                'width' => $width,
                'depth' => $depth,
                'sku' => $sku,
                'tax_category' => $tax_category
            );
        }
    }

    /**
     * Deletes field data
     *
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param array $fieldIds Array of field IDs
     * @param array $context
     *
     * @return boolean
     */
    public function deleteFieldData( VersionInfo $versionInfo, array $fieldIds, array $context )
    {
        $fields = $this->contentService->loadContentByVersionInfo( $versionInfo )->getFields();

        foreach ( $fields as $field )
        {
            if ( in_array( $field->id, $fieldIds ) )
            {
                /**@var \Netgen\Bundle\EzSyliusBundle\Core\FieldType\SyliusProduct\Value $value */
                $value = $field->value;
                $syliusId = $value->syliusId;

                if ( !empty ( $syliusId ) )
                {
                    $product = $this->repository->find( $syliusId );

                    $this->manager->remove( $product );
                    $this->manager->flush();
                }
            }
        }
    }

    /**
     * Checks if field type has external data to deal with
     *
     * @return boolean
     */
    public function hasFieldData()
    {
        return true;
    }

    /**
     * Get index data for external data for search backend
     *
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @param array $context
     *
     * @return \eZ\Publish\SPI\Search\Field[]
     */
    public function getIndexData( VersionInfo $versionInfo, Field $field, array $context )
    {
        return false;
    }

    /**
     * This method is used exclusively by Legacy Storage to copy external data of existing field in main language to
     * the untranslatable field not passed in create or update struct, but created implicitly in storage layer.
     *
     * By default the method falls back to the {@link \eZ\Publish\SPI\FieldType\FieldStorage::storeFieldData()}.
     * External storages implement this method as needed.
     *
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @param \eZ\Publish\SPI\Persistence\Content\Field $originalField
     * @param array $context
     *
     * @return null|boolean Same as {@link \eZ\Publish\SPI\FieldType\FieldStorage::storeFieldData()}.
     */
    public function copyLegacyField( VersionInfo $versionInfo, Field $field, Field $originalField, array $context )
    {
        return $this->storeFieldData( $versionInfo, $field, $context );
    }
}
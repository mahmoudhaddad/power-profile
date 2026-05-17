<?php

namespace Database\Seeders;

use App\Models\ComponentType;
use Illuminate\Database\Seeder;

class ComponentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            'Light', 'Fluorescent Lamp', 'LED Strip', 'Chandelier',
            'Air Conditioner', 'Fan', 'Ceiling Fan', 'Exhaust Fan',
            'Refrigerator', 'Freezer', 'Washing Machine', 'Dryer', 'Dishwasher',
            'TV', 'Computer', 'Laptop', 'Monitor', 'Printer', 'Projector', 'Server',
            'Microwave', 'Oven', 'Electric Stove', 'Coffee Machine', 'Toaster', 'Kettle',
            'Heater', 'Water Heater', 'Electric Radiator',
            'Elevator', 'Water Pump', 'Security Camera', 'Router', 'UPS',
        ];

        foreach ($presets as $name) {
            ComponentType::firstOrCreate(['name' => $name], ['is_preset' => true]);
        }
    }
}
